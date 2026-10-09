<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

it('lists notifications with unread count and marks them read', function () {
    $user = actingAsRole(UserRole::BudgetOfficer);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\ReconciliationStatusChanged',
        'data' => [
            'message' => 'Joe Ann D. Nisnisan submitted a reconciliation for review.',
            'period' => '2026-06-01 – 2026-06-30',
            'status_label' => 'For Review',
            'reconciliation_id' => 1,
        ],
    ]);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('data.0.data.message', 'Joe Ann D. Nisnisan submitted a reconciliation for review.');

    $id = $user->notifications()->first()->id;

    $this->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk();

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonPath('data.0.read_at', fn ($v) => $v !== null);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\ReconciliationStatusChanged',
        'data' => ['message' => 'Another update.', 'reconciliation_id' => 2],
    ]);

    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('scopes notifications to the signed-in user', function () {
    $owner = User::factory()->create(['role' => UserRole::BudgetOfficer->value]);
    $other = actingAsRole(UserRole::FinancialAnalyst);

    $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\ReconciliationStatusChanged',
        'data' => ['message' => 'Private for budget officer'],
    ]);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonCount(0, 'data');

    expect($other->id)->not->toBe($owner->id);
});

function notify(User $user, string $message, array $attributes = []): string
{
    $id = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $id,
        'type' => 'App\Notifications\ReconciliationStatusChanged',
        'data' => ['message' => $message],
    ] + $attributes);

    return $id;
}

it('filters notifications by read status and pages through them', function () {
    $user = actingAsRole(UserRole::BudgetOfficer);
    notify($user, 'unread one');
    notify($user, 'read one', ['read_at' => now()]);
    notify($user, 'read two', ['read_at' => now()]);

    $this->getJson('/api/v1/notifications?status=unread')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/notifications?status=read')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/notifications?per_page=2')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('total', 3)
        ->assertJsonPath('unread_count', 1);
    $this->getJson('/api/v1/notifications?status=bogus')->assertStatus(422);
});

it('deletes chosen notifications, and only ever the signed-in user\'s own', function () {
    $owner = User::factory()->create(['role' => UserRole::BudgetOfficer->value]);
    $theirs = notify($owner, 'not yours');
    $me = actingAsRole(UserRole::FinancialAnalyst);
    $a = notify($me, 'a');
    $b = notify($me, 'b');
    $keep = notify($me, 'keep');

    $this->postJson('/api/v1/notifications/bulk-delete', ['ids' => [$a, $b, $theirs]])
        ->assertOk()
        ->assertJsonPath('deleted', 2);

    expect($me->notifications()->pluck('id')->all())->toBe([$keep])
        ->and($owner->notifications()->count())->toBe(1);

    $this->postJson('/api/v1/notifications/bulk-delete', ['ids' => ['nope']])->assertStatus(422);
    $this->postJson('/api/v1/notifications/bulk-delete', ['ids' => []])->assertStatus(422);
});

it('clears the read ones, everything up to a date, or all notifications', function () {
    $me = actingAsRole(UserRole::FinancialAnalyst);
    notify($me, 'old unread', ['created_at' => now()->subDays(40)]);
    notify($me, 'old read', ['created_at' => now()->subDays(40), 'read_at' => now()]);
    notify($me, 'new read', ['read_at' => now()]);
    notify($me, 'new unread');

    $this->postJson('/api/v1/notifications/clear', ['mode' => 'read'])->assertOk()->assertJsonPath('deleted', 2);
    expect($me->notifications()->pluck('data')->pluck('message')->sort()->values()->all())->toBe(['new unread', 'old unread']);

    $this->postJson('/api/v1/notifications/clear', ['mode' => 'before'])->assertStatus(422);
    $this->postJson('/api/v1/notifications/clear', ['mode' => 'before', 'before' => now()->subDays(10)->toDateString()])
        ->assertOk()->assertJsonPath('deleted', 1);

    $this->postJson('/api/v1/notifications/clear', ['mode' => 'all'])->assertOk()->assertJsonPath('deleted', 1);
    expect($me->notifications()->count())->toBe(0);
});

it('deletes read notifications older than the person\'s own schedule, but never unread ones', function () {
    $me = actingAsRole(UserRole::FinancialAnalyst);
    $other = User::factory()->create(['role' => UserRole::BudgetOfficer->value, 'notification_retention_days' => null]);
    notify($me, 'old read', ['created_at' => now()->subDays(45), 'read_at' => now()->subDays(44)]);
    notify($me, 'old unread', ['created_at' => now()->subDays(45)]);
    notify($me, 'recent read', ['created_at' => now()->subDays(3), 'read_at' => now()]);
    notify($other, 'other old read', ['created_at' => now()->subDays(45), 'read_at' => now()]);

    $this->putJson('/api/v1/notifications/settings', ['retention_days' => 12])->assertStatus(422);

    $this->putJson('/api/v1/notifications/settings', ['retention_days' => 30])
        ->assertOk()
        ->assertJsonPath('retention_days', 30)
        ->assertJsonPath('deleted', 1);

    expect($me->notifications()->pluck('data')->pluck('message')->sort()->values()->all())->toBe(['old unread', 'recent read'])
        ->and($other->notifications()->count())->toBe(1);

    // The scheduled command applies everyone's setting, and leaves people with none alone.
    notify($me, 'another old read', ['created_at' => now()->subDays(60), 'read_at' => now()]);
    $this->artisan('finann:notifications-clean')->assertSuccessful();
    expect($me->notifications()->count())->toBe(2)
        ->and($other->notifications()->count())->toBe(1);

    $this->putJson('/api/v1/notifications/settings', ['retention_days' => null])->assertOk()->assertJsonPath('retention_days', null);
});
