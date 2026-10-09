<?php

use App\Enums\ReconciliationStatus;
use App\Models\CheckIssuance;
use App\Services\Reconciliation\CheckRegisterService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importing a Report of Checks Issued used to file every check under the reconciliation it was
     * uploaded into, whatever the check's date — so July checks ended up listed in October. A check
     * belongs to the period its date falls in. Move such checks to their own period; those sitting in
     * a period that is with the reviewer, or certified, are left exactly as certified.
     */
    public function up(): void
    {
        if (! Schema::hasTable('check_issuances')) {
            return;
        }

        $register = app(CheckRegisterService::class);
        $editable = [ReconciliationStatus::Draft->value, ReconciliationStatus::Returned->value];

        CheckIssuance::query()
            ->whereNotNull('check_date')
            ->whereHas('reconciliation', function ($q) use ($editable) {
                $q->whereIn('status', $editable)
                    ->where(fn ($outside) => $outside
                        ->whereColumn('check_issuances.check_date', '<', 'reconciliations.period_start')
                        ->orWhereColumn('check_issuances.check_date', '>', 'reconciliations.period_end'));
            })
            ->each(function (CheckIssuance $check) use ($register): void {
                $period = $register->periodFor($check->bank_account_id, $check->check_date->toDateString());

                // Its own period if that one can still change; otherwise unassigned (the register keeps it).
                $check->update(['reconciliation_id' => $period?->status->isEditable() ? $period->id : null]);
            });
    }

    public function down(): void
    {
        // Not reversible: the old tag was a mistake.
    }
};
