<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationStatus;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the shared Checks Register and the per-period reconciliations in step.
 *
 * A check belongs to a bank account; it is tagged to the reconciliation whose
 * period covers its date (when one exists) so period-based figures — such as
 * cancelled checks — know where it counts. Outstanding checks, by contrast,
 * are read account-wide, which is what lets one issued in July clear on a
 * later statement.
 */
class CheckRegisterService
{
    public function __construct(private readonly ReconciliationEngine $engine) {}

    /**
     * The reconciliation of this bank account whose period covers the date.
     */
    public function periodFor(int $bankAccountId, ?string $date): ?Reconciliation
    {
        if ($date === null) {
            return null;
        }

        return Reconciliation::query()
            ->where('bank_account_id', $bankAccountId)
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();
    }

    /**
     * A check can't be recorded into, or moved out of, a period that has
     * already gone to review or been certified.
     *
     * @throws ValidationException
     */
    public function assertPeriodOpen(?Reconciliation $period): void
    {
        if ($period !== null && ! $period->status->isEditable()) {
            throw ValidationException::withMessages([
                'check_date' => ['That date falls in a period that is already '.strtolower($period->status->label()).' and can no longer be changed.'],
            ]);
        }
    }

    /**
     * Tag a hand-entered check to the reconciliation covering its date.
     */
    public function assignPeriod(CheckIssuance $check): void
    {
        $period = $this->periodFor($check->bank_account_id, $check->check_date?->toDateString());

        if ($check->reconciliation_id !== $period?->id) {
            $check->update(['reconciliation_id' => $period?->id]);
        }
    }

    /**
     * Pick up register checks that were typed in before this period existed.
     */
    public function adoptUnassigned(Reconciliation $reconciliation): void
    {
        CheckIssuance::query()
            ->where('bank_account_id', $reconciliation->bank_account_id)
            ->whereNull('reconciliation_id')
            ->whereBetween('check_date', [$reconciliation->period_start->toDateString(), $reconciliation->period_end->toDateString()])
            ->update(['reconciliation_id' => $reconciliation->id]);
    }

    /**
     * Re-run the lighter engine refresh on every still-open reconciliation of
     * the account that a change to a check dated $fromDate can reach (that
     * period and any later one, since outstanding checks carry forward).
     */
    public function refreshOpenPeriods(int $bankAccountId, ?string $fromDate): void
    {
        Reconciliation::query()
            ->where('bank_account_id', $bankAccountId)
            ->whereIn('status', [ReconciliationStatus::Draft->value, ReconciliationStatus::Returned->value])
            ->when($fromDate !== null, fn ($q) => $q->whereDate('period_end', '>=', $fromDate))
            ->get()
            ->each(fn (Reconciliation $r) => $this->engine->refresh($r));
    }
}
