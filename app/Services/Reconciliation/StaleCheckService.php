<?php

namespace App\Services\Reconciliation;

use App\Enums\CheckStatus;
use App\Models\BankAccount;
use Carbon\CarbonInterface;

/**
 * Government checks are stale six months after issue. Marks still-outstanding
 * checks older than that so they can be reviewed and reverted.
 */
class StaleCheckService
{
    public function sweep(BankAccount $bankAccount, CarbonInterface $asOf): int
    {
        $cutoff = $asOf->copy()->subMonths(6)->startOfDay();

        return $bankAccount->checkIssuances()
            ->where('status', CheckStatus::Outstanding->value)
            ->whereNotNull('check_date')
            ->where('check_date', '<', $cutoff)
            ->update(['status' => CheckStatus::Stale->value]);
    }
}
