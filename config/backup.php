<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup storage directory
    |--------------------------------------------------------------------------
    */
    // Windows: a normal folder on the system drive (created automatically). Elsewhere: inside the app.
    'path' => env('BACKUP_PATH', PHP_OS_FAMILY === 'Windows'
        ? (getenv('SystemDrive') ?: 'C:').'\\FABReS Backups'
        : storage_path('app/backups')),

    // Where the schedule and chosen-folder settings are kept (tests point this elsewhere).
    'settings_path' => env('BACKUP_SETTINGS_PATH', storage_path('app/backup-schedule.json')),

    /*
    |--------------------------------------------------------------------------
    | Retention (days). Files older than this are removed after each backup.
    | Set 0 to keep all files.
    |--------------------------------------------------------------------------
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Automatic backup schedule (requires: php artisan schedule:run)
    | On Windows, run schedule:run via Task Scheduler every minute.
    |--------------------------------------------------------------------------
    */
    'schedule_enabled' => filter_var(env('BACKUP_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

];
