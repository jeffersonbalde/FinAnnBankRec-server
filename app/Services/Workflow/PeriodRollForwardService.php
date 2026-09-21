<?php

namespace App\Services\Workflow;

use App\Enums\ReconciliationStatus;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PeriodRollForwardService
{
    /**
     * Open the next period, seeding its unadjusted balances from this period's
     * adjusted balances. Outstanding checks carry over automatically because
     * they are keyed to the bank account, not the period.
     */
    public function createNextPeriod(Reconciliation $reconciliation, User $actor): Reconciliation
    {
        if ($reconciliation->status !== ReconciliationStatus::Certified) {
            throw ValidationException::withMessages([
                'status' => ['Only a certified reconciliation can be rolled forward.'],
            ]);
        }

        $nextStart = $reconciliation->period_end->copy()->addDay()->startOfDay();
        $nextEnd = $reconciliation->period_type === 'weekly'
            ? $nextStart->copy()->addDays(6)
            : $nextStart->copy()->endOfMonth();

        $existing = Reconciliation::query()
            ->where('bank_account_id', $reconciliation->bank_account_id)
            ->whereDate('period_start', $nextStart->toDateString())
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'period' => ['The next period already has a reconciliation.'],
            ]);
        }

        return Reconciliation::create([
            'bank_account_id' => $reconciliation->bank_account_id,
            'period_type' => $reconciliation->period_type,
            'period_start' => $nextStart->toDateString(),
            'period_end' => $nextEnd->toDateString(),
            'statement_label' => 'As of '.$nextEnd->format('F j, Y'),
            'unadjusted_book_balance' => $reconciliation->adjusted_book_balance,
            'unadjusted_bank_balance' => $reconciliation->adjusted_bank_balance,
            'status' => ReconciliationStatus::Draft,
            'prepared_by' => $actor->id,
        ]);
    }
}
