<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes all reconciliation activity (sample or real) while keeping the master
 * data: users, bank accounts + signatories and UACS codes. A SQL backup is
 * written first so the wipe can be undone.
 */
class SampleDataService
{
    /**
     * Children before parents.
     *
     * @var list<string>
     */
    public const TABLES = [
        'reconciling_items',
        'match_runs',
        'bank_transactions',
        'check_issuances',
        'import_batches',
        'reconciliations',
        'notifications',
        'audit_logs',
    ];

    public function __construct(private readonly DatabaseBackupService $backups) {}

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $counts;
    }

    /**
     * @return array{backup: string, deleted: array<string, int>}
     */
    public function clear(): array
    {
        $backup = $this->backups->writeToDisk();
        $deleted = $this->counts();

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Storage::deleteDirectory('imports');

        return ['backup' => $backup['name'], 'deleted' => $deleted];
    }
}
