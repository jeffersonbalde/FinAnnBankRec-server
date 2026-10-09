<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /**
     * Summary figures for the dashboard. Everything can be narrowed to a date
     * range and/or one bank account; with no filters it covers all time.
     *
     * - Reconciliation figures use the periods that overlap the range.
     * - Check figures use the checks whose date falls inside the range.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
        ]);

        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        $bankAccountId = $filters['bank_account_id'] ?? null;

        $reconciliations = fn (): Builder => $this->scopeReconciliations(Reconciliation::query(), $from, $to, $bankAccountId);
        $checks = fn (): Builder => $this->scopeChecks(CheckIssuance::query(), $from, $to, $bankAccountId);

        $today = CarbonImmutable::now();

        $outstanding = $checks()
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
            ->get(['amount', 'status', 'check_date']);

        $issued = $checks()->where('status', '!=', CheckStatus::Cancelled->value);

        return response()->json([
            'filters' => ['date_from' => $from, 'date_to' => $to, 'bank_account_id' => $bankAccountId],
            'open_reconciliations' => $reconciliations()
                ->whereIn('status', [
                    ReconciliationStatus::Draft->value,
                    ReconciliationStatus::ForReview->value,
                    ReconciliationStatus::Returned->value,
                ])->count(),
            'for_review' => $reconciliations()->where('status', ReconciliationStatus::ForReview->value)->count(),
            'certified' => $reconciliations()->where('status', ReconciliationStatus::Certified->value)->count(),
            'checks_issued_count' => (clone $issued)->count(),
            'checks_issued_amount' => round((float) (clone $issued)->sum('amount'), 2),
            'outstanding_checks_count' => $outstanding->count(),
            'outstanding_checks_amount' => round((float) $outstanding->sum('amount'), 2),
            'stale_checks_count' => $outstanding->where('status', CheckStatus::Stale->value)->count(),
            'aging' => $this->aging($outstanding, $today),
            'per_fund' => $this->perFund($from, $to, $bankAccountId),
            'status_breakdown' => $this->statusBreakdown($reconciliations()),
            'balance_trend' => $this->balanceTrend($reconciliations()),
        ]);
    }

    /**
     * @param  Builder<Reconciliation>  $query
     * @return Builder<Reconciliation>
     */
    private function scopeReconciliations(Builder $query, ?string $from, ?string $to, ?int $bankAccountId): Builder
    {
        return $query
            ->when($bankAccountId, fn (Builder $q) => $q->where('bank_account_id', $bankAccountId))
            // A period is in range when it overlaps it, so a weekly or monthly one is never half-counted.
            ->when($from, fn (Builder $q) => $q->whereDate('period_end', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('period_start', '<=', $to));
    }

    /**
     * @param  Builder<CheckIssuance>  $query
     * @return Builder<CheckIssuance>
     */
    private function scopeChecks(Builder $query, ?string $from, ?string $to, ?int $bankAccountId): Builder
    {
        return $query
            ->when($bankAccountId, fn (Builder $q) => $q->where('bank_account_id', $bankAccountId))
            ->when($from, fn (Builder $q) => $q->whereDate('check_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('check_date', '<=', $to));
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
     * @param  Builder<Reconciliation>  $reconciliations
     * @return list<array{status: string, label: string, count: int}>
     */
    private function statusBreakdown(Builder $reconciliations): array
    {
        $counts = $reconciliations
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
     * The last 8 reconciliations in range (across the chosen bank accounts) in
     * period order, for a book-vs-bank balance trend chart.
     *
     * @param  Builder<Reconciliation>  $reconciliations
     * @return list<array<string, mixed>>
     */
    private function balanceTrend(Builder $reconciliations): array
    {
        return $reconciliations
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
     * Each active bank account with its latest reconciliation inside the range.
     *
     * @return list<array<string, mixed>>
     */
    private function perFund(?string $from, ?string $to, ?int $bankAccountId): array
    {
        return BankAccount::query()
            ->where('is_active', true)
            ->when($bankAccountId, fn (Builder $q) => $q->whereKey($bankAccountId))
            ->with(['reconciliations' => fn ($q) => $q
                ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to))
                ->latest('period_end')
                ->limit(1)])
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
