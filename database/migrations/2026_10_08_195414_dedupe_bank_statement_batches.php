<?php

use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replacing a bank statement used to add the new lines on top of the old ones, so some
     * periods carry the same statement two or three times. A period has one statement: keep
     * the latest in every period that can still change, and drop the older ones. Certified and
     * For Review periods are left exactly as they were certified.
     */
    public function up(): void
    {
        if (! Schema::hasTable('import_batches')) {
            return;
        }

        $manager = app(ImportManager::class);

        Reconciliation::query()
            ->whereIn('status', [ReconciliationStatus::Draft->value, ReconciliationStatus::Returned->value])
            ->whereIn('id', ImportBatch::query()
                ->where('type', ImportType::BankStatement->value)
                ->where('status', 'committed')
                ->whereNotNull('reconciliation_id')
                ->groupBy('reconciliation_id')
                ->havingRaw('count(*) > 1')
                ->select('reconciliation_id'))
            ->each(function (Reconciliation $reconciliation) use ($manager): void {
                $latest = ImportBatch::query()
                    ->where('reconciliation_id', $reconciliation->id)
                    ->where('type', ImportType::BankStatement->value)
                    ->where('status', 'committed')
                    ->latest('id')
                    ->first();

                $manager->discardOtherStatements($latest, $reconciliation);
                $manager->restoreStatementBalance($latest);
                app(ReconciliationEngine::class)->run($reconciliation->fresh(), null);
            });
    }

    public function down(): void
    {
        // The removed duplicate lines cannot be brought back; they were copies of the statement kept.
    }
};
