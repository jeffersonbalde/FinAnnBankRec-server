<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Http\Controllers\Controller;
use App\Models\CheckIssuance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutstandingCheckController extends Controller
{
    /**
     * Cross-period register of outstanding + stale checks with aging.
     */
    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::now();
        $search = trim((string) $request->input('search', ''));

        $baseQuery = CheckIssuance::query()
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(function ($inner) use ($like) {
                    $inner->where('serial_no', 'like', $like)
                        ->orWhere('payee', 'like', $like)
                        ->orWhereHas('bankAccount', function ($account) use ($like) {
                            $account->where('account_number', 'like', $like)
                                ->orWhere('bank_short_name', 'like', $like)
                                ->orWhere('bank_name', 'like', $like)
                                ->orWhere('fund_cluster', 'like', $like);
                        });
                });
            });

        $summary = [
            'count' => (clone $baseQuery)->count(),
            'amount' => round((float) (clone $baseQuery)->sum('amount'), 2),
            'stale_count' => (clone $baseQuery)->where('status', CheckStatus::Stale->value)->count(),
        ];

        $checks = (clone $baseQuery)
            ->with('bankAccount:id,bank_short_name,bank_name,account_number,fund_cluster')
            ->orderBy('check_date')
            ->orderBy('serial_no')
            ->paginate(min($request->integer('per_page', 25), 100));

        $rows = $checks->getCollection()->map(function (CheckIssuance $check) use ($today) {
            $days = $check->check_date ? (int) $check->check_date->diffInDays($today) : null;

            return [
                'id' => $check->id,
                'bank_account_id' => $check->bank_account_id,
                'bank_account' => $check->bankAccount
                    ? ($check->bankAccount->bank_short_name ?: $check->bankAccount->bank_name).' · '.$check->bankAccount->account_number
                    : null,
                'fund_cluster' => $check->bankAccount?->fund_cluster,
                'check_date' => $check->check_date?->toDateString(),
                'serial_no' => $check->serial_no,
                'payee' => $check->payee,
                'amount' => (float) $check->amount,
                'status' => $check->status->value,
                'days_outstanding' => $days,
                'is_stale' => $check->status === CheckStatus::Stale,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $checks->currentPage(),
                'last_page' => $checks->lastPage(),
                'per_page' => $checks->perPage(),
                'from' => $checks->firstItem(),
                'to' => $checks->lastItem(),
                'total' => $checks->total(),
            ],
            'summary' => $summary,
        ]);
    }
}
