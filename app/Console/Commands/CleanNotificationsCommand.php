<?php

namespace App\Console\Commands;

use App\Services\NotificationRetentionService;
use Illuminate\Console\Command;

class CleanNotificationsCommand extends Command
{
    protected $signature = 'finann:notifications-clean';

    protected $description = 'Delete read notifications older than each person\'s own auto-delete setting.';

    public function handle(NotificationRetentionService $retention): int
    {
        $deleted = $retention->run();

        $this->info("Deleted {$deleted} old read notifications.");

        return self::SUCCESS;
    }
}
