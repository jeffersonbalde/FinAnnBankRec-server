<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('finann:activity-clean')
    ->dailyAt('02:00')
    ->name('finann-activity-clean')
    ->withoutOverlapping();

Schedule::command('finann:backup-run-scheduled')
    ->everyMinute()
    ->name('finann-scheduled-backup')
    ->withoutOverlapping();

Schedule::command('finann:notifications-clean')
    ->dailyAt('02:10')
    ->name('finann-notifications-clean')
    ->withoutOverlapping();
