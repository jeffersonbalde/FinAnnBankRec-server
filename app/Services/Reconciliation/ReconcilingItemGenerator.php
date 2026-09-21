<?php

namespace App\Services\Reconciliation;

use App\Enums\BankTransactionType;
use App\Enums\CheckStatus;
use App\Enums\ReconcilingItemCategory;
use App\Models\Reconciliation;

/**
 * Rebuilds the engine-managed reconciling items for a reconciliation from the
 * current check / bank-transaction data. Manual items entered by the analyst
 * are left untouched.
 */
class ReconcilingItemGenerator
{
    public function generate(Reconciliation $reconciliation): void
    {
        $reconciliation->reconcilingItems()->where('is_auto_generated', true)->delete();

        $this->outstandingChecks($reconciliation);
        $this->cancelledChecks($reconciliation);
        $this->bankSideFromTransactions($reconciliation);
    }

    private function cancelledChecks(Reconciliation $reconciliation): void
    {
        $total = $reconciliation->checkIssuances()
            ->where('status', CheckStatus::Cancelled->value)
            ->sum('amount');

        $this->put($reconciliation, ReconcilingItemCategory::CancelledCheck, (float) $total, 'Schedule 4',
            'Checks recorded as issued but later cancelled — added back to the book balance.');
    }

    private function outstandingChecks(Reconciliation $reconciliation): void
    {
        $total = $reconciliation->bankAccount
            ->checkIssuances()
            ->where('status', CheckStatus::Outstanding->value)
            ->where(fn ($q) => $q->whereNull('check_date')->orWhere('check_date', '<=', $reconciliation->period_end))
            ->sum('amount');

        $this->put($reconciliation, ReconcilingItemCategory::OutstandingCheck, (float) $total, 'Schedule 1',
            'Checks issued but not yet presented to the bank.');
    }

    private function bankSideFromTransactions(Reconciliation $reconciliation): void
    {
        $transactions = $reconciliation->bankTransactions()->get();

        $charges = $transactions->where('derived_type', BankTransactionType::BankCharge)->sum('debit');
        $interest = $transactions->where('derived_type', BankTransactionType::InterestCredit)->sum('credit');
        $returned = $transactions->where('derived_type', BankTransactionType::ReturnedCheck)->sum('debit');

        $this->put($reconciliation, ReconcilingItemCategory::BankCharge, (float) $charges, 'Schedule 2',
            'Bank service charges / debit memos not yet recorded in the books.');
        $this->put($reconciliation, ReconcilingItemCategory::UnrecordedCredit, (float) $interest, null,
            'Interest income / credit memos not yet recorded in the books.');
        $this->put($reconciliation, ReconcilingItemCategory::ReturnedCheck, (float) $returned, 'Schedule 3',
            'Deposited checks returned by the bank (DAIF/DAUD).');
    }

    private function put(Reconciliation $reconciliation, ReconcilingItemCategory $category, float $amount, ?string $schedule, string $comment): void
    {
        if (round($amount, 2) <= 0) {
            return;
        }

        $reconciliation->reconcilingItems()->create([
            'side' => $category->side(),
            'category' => $category,
            'operation' => $category->operation(),
            'amount' => round($amount, 2),
            'explanatory_comment' => $comment,
            'schedule_no' => $schedule,
            'is_auto_generated' => true,
        ]);
    }
}
