<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = CarbonImmutable::now();

        $outstanding = CheckIssuance::query()
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
            ->get(['amount', 'status', 'check_date']);

        return response()->json([
            'open_reconciliations' => Reconciliation::query()
                ->whereIn('status', [
                    ReconciliationStatus::Draft->value,
                    ReconciliationStatus::ForReview->value,
                    ReconciliationStatus::Returned->value,
                ])->count(),
            'for_review' => Reconciliation::query()->where('status', ReconciliationStatus::ForReview->value)->count(),
            'outstanding_checks_count' => $outstanding->count(),
            'outstanding_checks_amount' => round((float) $outstanding->sum('amount'), 2),
            'stale_checks_count' => $outstanding->where('status', CheckStatus::Stale->value)->count(),
            'aging' => $this->aging($outstanding, $today),
            'per_fund' => $this->perFund(),
            'status_breakdown' => $this->statusBreakdown(),
            'balance_trend' => $this->balanceTrend(),
            'recent_activity' => AuditLog::query()
                ->latest()
                ->limit(12)
                ->get(['user_name', 'action', 'description', 'auditable_type', 'auditable_id', 'created_at'])
                ->map(fn (AuditLog $log) => [
                    'who' => $log->user_name ?? 'System',
                    'what' => $log->description,
                    'when' => $log->created_at,
                ]),
        ]);
    }

    /**
     * @param  Collection<int, CheckIssuance>  $checks
     * @return array<string, array{count: int, amount: float}>
     */
    private function aging($checks, CarbonImmutable $today): array
    {
        $buckets = ['0-30' => [], '31-60' => [], '61-90' => [], '91-180' => [], '180+' => []];

        foreach ($checks as $check) {
            $days = $check->check_date ? $check->check_date->diffInDays($today) : 0;
            $key = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                $days <= 180 => '91-180',
                default => '180+',
            };
            $buckets[$key][] = (float) $check->amount;
        }

        return collect($buckets)->map(fn (array $amounts) => [
            'count' => count($amounts),
            'amount' => round(array_sum($amounts), 2),
        ])->all();
    }

    /**
     * Every reconciliation's status, counted — including statuses with
     * zero records, so a pie/donut chart always shows the full workflow.
     *
     * @return list<array{status: string, label: string, count: int}>
     */
    private function statusBreakdown(): array
    {
        $counts = Reconciliation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(ReconciliationStatus::cases())
            ->map(fn (ReconciliationStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ])
            ->all();
    }

    /**
     * The last 8 reconciliations (across all bank accounts) in period
     * order, for a book-vs-bank balance trend chart.
     *
     * @return list<array<string, mixed>>
     */
    private function balanceTrend(): array
    {
        return Reconciliation::query()
            ->with('bankAccount')
            ->orderByDesc('period_end')
            ->limit(8)
            ->get()
            ->sortBy('period_end')
            ->values()
            ->map(fn (Reconciliation $r) => [
                'period_end' => $r->period_end?->toDateString(),
                'bank_account' => $r->bankAccount ? ($r->bankAccount->bank_short_name ?: $r->bankAccount->bank_name) : null,
                'adjusted_book_balance' => (float) $r->adjusted_book_balance,
                'adjusted_bank_balance' => (float) $r->adjusted_bank_balance,
                'difference' => (float) $r->difference,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perFund(): array
    {
        return BankAccount::query()
            ->where('is_active', true)
            ->with(['reconciliations' => fn ($q) => $q->latest('period_end')->limit(1)])
            ->get()
            ->map(function (BankAccount $account) {
                $latest = $account->reconciliations->first();

                return [
                    'bank_account' => ($account->bank_short_name ?: $account->bank_name).' · '.$account->account_number,
                    'fund_cluster' => $account->fund_cluster,
                    'latest_period' => $latest?->period_end?->toDateString(),
                    'status' => $latest?->status->value,
                    'status_label' => $latest?->status->label(),
                    'difference' => $latest ? (float) $latest->difference : null,
                ];
            })
            ->all();
    }
}
