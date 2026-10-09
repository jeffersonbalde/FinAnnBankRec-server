<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;

/**
 * A person's auto-delete schedule for their notifications: read ones older than
 * the number of days they chose are deleted. Unread ones are always kept, so a
 * request that still waits for action never disappears.
 */
class NotificationRetentionService
{
    /** Choices a person may pick, in days. */
    public const OPTIONS = [7, 30, 90, 180, 365];

    public function setRetentionDays(User $user, ?int $days): void
    {
        // Quietly: changing a preference is not itself a footprint worth a row.
        $user->forceFill(['notification_retention_days' => $days])->saveQuietly();
    }

    /** Delete a person's read notifications older than their own setting. Returns how many. */
    public function clearOldFor(User $user): int
    {
        if ($user->notification_retention_days === null) {
            return 0;
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays($user->notification_retention_days))
            ->delete();
    }

    /** Apply everyone's schedule now. Returns how many notifications were deleted. */
    public function run(): int
    {
        $deleted = 0;

        User::query()->whereNotNull('notification_retention_days')->get()->each(
            function (User $user) use (&$deleted): void {
                $deleted += $this->clearOldFor($user);
            },
        );

        return $deleted;
    }

    /** Catch-up for hosting without a scheduler: at most once every few hours, when someone opens Notifications. */
    public function runIfDue(): void
    {
        if (Cache::add('notification-retention:ran', true, now()->addHours(6))) {
            $this->run();
        }
    }
}
