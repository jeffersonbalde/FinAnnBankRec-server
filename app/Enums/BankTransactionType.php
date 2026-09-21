<?php

namespace App\Enums;

enum BankTransactionType: string
{
    case CheckClearing = 'check_clearing';
    case Deposit = 'deposit';
    case BankCharge = 'bank_charge';
    case InterestCredit = 'interest_credit';
    case ReturnedCheck = 'returned_check';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CheckClearing => 'Check clearing',
            self::Deposit => 'Deposit',
            self::BankCharge => 'Bank charge',
            self::InterestCredit => 'Interest credit',
            self::ReturnedCheck => 'Returned check',
            self::Other => 'Other',
        };
    }
}
