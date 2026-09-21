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
