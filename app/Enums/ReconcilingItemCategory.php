<?php

namespace App\Enums;

enum ReconcilingItemCategory: string
{
    // Bank side
    case DepositInTransit = 'deposit_in_transit';
    case ErrorUnderstatingBank = 'error_understating_bank';
    case OutstandingCheck = 'outstanding_check';
    case ErrorOverstatingBank = 'error_overstating_bank';

    // Book side
    case UnrecordedCredit = 'unrecorded_credit';
    case CancelledCheck = 'cancelled_check';
    case ErrorUnderstatingBook = 'error_understating_book';
    case BankCharge = 'bank_charge';
    case ReturnedCheck = 'returned_check';
    case ErrorOverstatingBook = 'error_overstating_book';

    public function side(): string
    {
        return match ($this) {
            self::DepositInTransit, self::ErrorUnderstatingBank,
            self::OutstandingCheck, self::ErrorOverstatingBank => 'bank',
            default => 'book',
        };
    }

    public function operation(): string
    {
        return match ($this) {
            self::DepositInTransit, self::ErrorUnderstatingBank,
            self::UnrecordedCredit, self::CancelledCheck, self::ErrorUnderstatingBook => 'add',
            default => 'deduct',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DepositInTransit => 'Unrecorded Deposit / Deposit in Transit',
            self::ErrorUnderstatingBank => 'Errors which understate the bank balance',
            self::OutstandingCheck => 'Outstanding Checks',
            self::ErrorOverstatingBank => 'Errors which overstate the bank balance',
            self::UnrecordedCredit => 'Unrecorded Deposit / Credit Memo',
            self::CancelledCheck => 'Cancelled Checks',
            self::ErrorUnderstatingBook => 'Errors which understate the book balance',
            self::BankCharge => 'Bank Charges',
            self::ReturnedCheck => 'Returned Check Deposits',
            self::ErrorOverstatingBook => 'Errors which overstate the book balance',
        };
    }

    /**
     * Categories an analyst may add by hand (auto ones are engine-managed).
     *
     * @return list<self>
     */
    public static function manualCases(): array
    {
        return [
            self::ErrorUnderstatingBank,
            self::ErrorOverstatingBank,
            self::ErrorUnderstatingBook,
            self::ErrorOverstatingBook,
            self::DepositInTransit,
            self::UnrecordedCredit,
            self::CancelledCheck,
        ];
    }
}
