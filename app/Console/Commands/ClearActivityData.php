<?php

namespace App\Console\Commands;

use App\Services\SampleDataService;
use Illuminate\Console\Command;

class ClearActivityData extends Command
{
    protected $signature = 'app:clear-activity-data {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all reconciliations, checks, bank transactions, notifications and audit logs (keeps users, bank accounts and UACS codes)';

    public function handle(SampleDataService $service): int
    {
        $counts = $service->counts();

        $this->table(['Table', 'Rows to delete'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());

        if (! $this->option('force') && ! $this->confirm('Delete all of the above? A SQL backup is written first.')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $result = $service->clear();

        $this->info('Cleared. Backup saved as '.$result['backup'].'.');

        return self::SUCCESS;
    }
}
