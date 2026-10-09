<?php

namespace App\Console\Commands;

use App\Services\ActivityRetentionService;
use Illuminate\Console\Command;

class CleanActivityHistoryCommand extends Command
{
    protected $signature = 'finann:activity-clean';

    protected $description = 'Apply the auto-clear schedules for activity history (each person\'s own, and the system-wide one).';

    public function handle(ActivityRetentionService $retention): int
    {
        $result = $retention->run();

        $this->info("Hid {$result['hidden']} entries from personal histories; deleted {$result['purged']} old audit entries.");

        return self::SUCCESS;
    }
}
