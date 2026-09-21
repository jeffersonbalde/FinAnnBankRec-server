<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class RunScheduledBackupCommand extends Command
{
    protected $signature = 'finann:backup-run-scheduled';

    protected $description = 'Create a backup when the admin-configured schedule is due.';

    public function handle(BackupScheduleService $schedule, DatabaseBackupService $backups): int
    {
        $created = $schedule->runIfDue($backups);

        if (! $created) {
            $this->line('No scheduled backup due.');

            return self::SUCCESS;
        }

        AuditLog::create([
            'user_id' => null,
            'user_name' => 'System',
            'action' => 'system.backup_scheduled',
            'auditable_type' => 'system',
            'auditable_id' => 0,
            'description' => 'Scheduled backup created',
            'changes' => [
                'name' => $created['name'],
                'size' => $created['size'],
                'format' => 'sql',
            ],
            'ip_address' => null,
        ]);

        $this->info('Scheduled backup created: '.$created['name']);

        return self::SUCCESS;
    }
}
