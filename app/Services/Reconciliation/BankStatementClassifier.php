<?php

namespace App\Services\Reconciliation;

use App\Enums\BankTransactionType;
use App\Models\BankTransaction;
use App\Models\Reconciliation;

/**
 * Assigns a derived type to each bank transaction from its description and
 * debit/credit shape, so the reconciling-item generator knows how to treat it.
 */
class BankStatementClassifier
{
    /** @var array<string, list<string>> */
    private const KEYWORDS = [
        BankTransactionType::BankCharge->value => ['service charge', 'debit memo', 'dm ', 'bank charge', 'penalty', 'fee', 'commission'],
        BankTransactionType::InterestCredit->value => ['interest', 'credit memo', 'int. earned', 'int earned'],
        BankTransactionType::ReturnedCheck->value => ['returned', 'daif', 'daud', 'dishonored', 'refer to drawer', 'rtc'],
        BankTransactionType::Deposit->value => ['deposit', 'cash dep', 'fund transfer credit', 'collection'],
    ];

    public function classifyReconciliation(Reconciliation $reconciliation): void
    {
        $reconciliation->bankTransactions()->get()->each(function (BankTransaction $txn): void {
            $txn->update(['derived_type' => $this->classify($txn)]);
        });
    }

    public function classify(BankTransaction $txn): BankTransactionType
    {
        if ($txn->is_balance_forward) {
            return BankTransactionType::Other;
        }

        $description = mb_strtolower((string) $txn->description);

        foreach (self::KEYWORDS as $type => $needles) {
            foreach ($needles as $needle) {
                if ($description !== '' && str_contains($description, trim($needle))) {
                    return BankTransactionType::from($type);
                }
            }
        }

        // A debit with a check number is a cleared check.
        if ((float) $txn->debit > 0 && filled($txn->check_no)) {
            return BankTransactionType::CheckClearing;
        }

        // A debit without a check number is most likely a bank charge.
        if ((float) $txn->debit > 0) {
            return BankTransactionType::BankCharge;
        }

        // Any credit is money coming in.
        if ((float) $txn->credit > 0) {
            return BankTransactionType::Deposit;
        }

        return BankTransactionType::Other;
    }
}
