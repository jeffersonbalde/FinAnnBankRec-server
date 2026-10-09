<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The two auto-clear schedules for activity history:
 *
 *  - each person's own ("clear my activity older than N days") hides their old
 *    entries from their list but leaves the audit copy for the administrator;
 *  - the administrator's system-wide one permanently deletes audit entries older
 *    than N days.
 */
class ActivityRetentionService
{
    /** Choices a person may pick for their own history, in days. */
    public const USER_OPTIONS = [7, 30, 90, 180, 365];

    /** Choices the administrator may pick for the system-wide audit trail, in days. */
    public const SYSTEM_OPTIONS = [90, 180, 365, 730];

    private const SYSTEM_KEY = 'audit_retention_days';

    private const LAST_RUN_KEY = 'activity_retention_last_run';

    public function systemRetentionDays(): ?int
    {
        $value = SystemSetting::read(self::SYSTEM_KEY);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function setSystemRetentionDays(?int $days): void
    {
        SystemSetting::write(self::SYSTEM_KEY, $days === null ? null : (string) $days);
    }

    public function lastRunAt(): ?string
    {
        return SystemSetting::read(self::LAST_RUN_KEY);
    }

    public function setUserRetentionDays(User $user, ?int $days): void
    {
        // Quietly: changing a preference is not itself a footprint worth a row.
        $user->forceFill(['activity_retention_days' => $days])->saveQuietly();
    }

    /** Hide a person's entries older than their own setting from their list. Returns how many. */
    public function clearOldFor(User $user): int
    {
        if ($user->activity_retention_days === null) {
            return 0;
        }

        return AuditLog::query()
            ->where('user_id', $user->id)
            ->whereNull('hidden_at')
            ->where('created_at', '<', now()->subDays($user->activity_retention_days))
            ->update(['hidden_at' => now()]);
    }

    /**
     * Apply both schedules now.
     *
     * @return array{hidden: int, purged: int}
     */
    public function run(): array
    {
        $hidden = 0;

        User::query()->whereNotNull('activity_retention_days')->get(['id', 'activity_retention_days'])->each(
            function (User $user) use (&$hidden): void {
                $hidden += $this->clearOldFor($user);
            },
        );

        $purged = 0;
        if (($days = $this->systemRetentionDays()) !== null) {
            $purged = AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
        }

        SystemSetting::write(self::LAST_RUN_KEY, now()->toIso8601String());

        return ['hidden' => $hidden, 'purged' => $purged];
    }

    /**
     * Catch-up for hosting without a scheduler: runs at most once every few hours,
     * triggered by someone opening an activity screen.
     */
    public function runIfDue(): void
    {
        if (Cache::add('activity-retention:ran', true, now()->addHours(6))) {
            $this->run();
        }
    }
}
