<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A footprint with a chosen owner and age. */
function footprint(User $user, array $overrides = []): AuditLog
{
    return AuditLog::query()->forceCreate(array_merge([
        'user_id' => $user->id,
        'user_name' => $user->name,
        'action' => 'updated',
        'auditable_type' => 'App\Models\BankAccount',
        'auditable_id' => 1,
        'description' => 'BankAccount updated',
        'changes' => ['fund_cluster' => '101-GF'],
        'ip_address' => '127.0.0.1',
        'created_at' => now(),
    ], $overrides));
}

/** Requests as the SPA sends them, so the cookie-session login flow starts. */
function activitySpa(): TestCase
{
    return test()->withHeader('Origin', config('app.url'));
}

beforeEach(fn () => Cache::flush());

it('records signing in and out, and changing a password, as footprints', function () {
    $user = User::factory()->role(UserRole::FinancialAnalyst)->create(['email' => 'who@example.test', 'password' => 'secret-pass']);

    activitySpa()->postJson('/api/v1/login', ['email' => 'who@example.test', 'password' => 'secret-pass'])->assertOk();
    $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login', 'description' => 'Signed in to the system']);

    activitySpa()->postJson('/api/v1/me/password', [
        'current_password' => 'secret-pass',
        'password' => 'another-pass-1',
        'password_confirmation' => 'another-pass-1',
    ])->assertOk();
    $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'password_changed']);

    activitySpa()->postJson('/api/v1/logout')->assertOk();
    $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'logout', 'description' => 'Signed out of the system']);
});

it('records exports and manual matching as footprints', function () {
    $user = actingAsRole(UserRole::DisbursingOfficer);
    CheckIssuance::factory()->for(BankAccount::factory()->create())->create();

    $this->get('/api/v1/check-register/export.xlsx')->assertOk();
    $this->get('/api/v1/outstanding-checks/export.xlsx')->assertOk();

    expect(AuditLog::query()->where('user_id', $user->id)->where('action', 'exported')->pluck('description')->all())
        ->toContain('Downloaded the Checks Register (Excel)', 'Downloaded the Outstanding Checks list (Excel)');
});

it('shows a person only their own activity, newest first, in plain words', function () {
    $me = actingAsRole(UserRole::FinancialAnalyst);
    $other = User::factory()->create();
    AuditLog::query()->delete(); // creating the user above left a footprint of its own

    footprint($me, ['created_at' => now()->subHour(), 'description' => 'Reconciliation updated', 'auditable_type' => 'App\Models\Reconciliation', 'auditable_id' => 14, 'changes' => ['status' => 'for_review', 'updated_at' => 'x']]);
    footprint($me, ['created_at' => now(), 'action' => 'exported', 'description' => 'Downloaded the BRS (Excel) of reconciliation #14']);
    footprint($other, ['description' => 'Someone else did this']);

    $rows = $this->getJson('/api/v1/my-activity')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['summary'])->toBe('Downloaded the BRS (Excel) of reconciliation #14')
        ->and($rows[0]['kind'])->toBe('exported')
        ->and($rows[1]['summary'])->toBe('Moved reconciliation #14 to For Review')
        ->and($rows[1]['entity'])->toBe('Reconciliation #14')
        ->and($rows[1]['details'])->toBe(['Status: for_review'])
        ->and(collect($rows)->pluck('who')->unique()->all())->toBe([$me->name]);
});

it('filters My Activity by search, kind and date, with counts for the cards', function () {
    $me = actingAsRole(UserRole::BudgetOfficer);
    footprint($me, ['action' => 'login', 'description' => 'Signed in', 'created_at' => now()->subDays(10)]);
    footprint($me, ['action' => 'exported', 'description' => 'Exported the Checks Register (Excel)', 'created_at' => now()]);
    footprint($me, ['action' => 'deleted', 'description' => 'Check 000100001 removed', 'created_at' => now()->subDays(2)]);

    $this->getJson('/api/v1/my-activity?action=exported')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/my-activity?search=000100001')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/my-activity?date_from='.now()->subDays(3)->toDateString())->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/my-activity')->assertOk()
        ->assertJsonPath('summary.total', 3)
        ->assertJsonPath('summary.today', 1)
        ->assertJsonPath('settings.retention_days', null);
});

it('lets a person clear entries from their own list, but never someone else’s', function () {
    $me = actingAsRole(UserRole::DisbursingOfficer);
    $other = User::factory()->create();
    $mine = footprint($me);
    $mine2 = footprint($me);
    $theirs = footprint($other);

    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'ids', 'ids' => [$mine->id, $theirs->id]])
        ->assertOk()
        ->assertJsonPath('cleared', 1);

    expect($mine->fresh()->hidden_at)->not->toBeNull()
        ->and($mine2->fresh()->hidden_at)->toBeNull()
        ->and($theirs->fresh()->hidden_at)->toBeNull();

    // The list no longer shows it — but the act of clearing is itself a footprint.
    $summaries = collect($this->getJson('/api/v1/my-activity')->json('data'))->pluck('summary');
    expect($summaries)->toContain('Cleared 1 entry from your history');

    // The administrator still has the audit copy, flagged as cleared by the person.
    actingAsRole(UserRole::Admin);
    $row = collect($this->getJson('/api/v1/audit-logs?per_page=200')->json('data'))->firstWhere('id', $mine->id);
    expect($row['cleared_by_user'])->toBeTrue();
});

it('clears everything up to a date, or all of it, and rejects bad requests', function () {
    $me = actingAsRole(UserRole::FinancialAnalyst);
    $old = footprint($me, ['created_at' => now()->subDays(40)]);
    $recent = footprint($me, ['created_at' => now()->subDay()]);

    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'before', 'before' => now()->subDays(30)->toDateString()])
        ->assertOk()->assertJsonPath('cleared', 1);
    expect($old->fresh()->hidden_at)->not->toBeNull()->and($recent->fresh()->hidden_at)->toBeNull();

    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'all'])->assertOk()->assertJsonPath('cleared', 2);
    expect($recent->fresh()->hidden_at)->not->toBeNull();

    // Nothing left to clear: no empty "cleared 0" footprint either.
    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'all'])->assertOk()->assertJsonPath('cleared', 1); // the trace of the first clear

    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'ids'])->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'before'])->assertUnprocessable()->assertJsonValidationErrors('before');
    $this->postJson('/api/v1/my-activity/clear', ['mode' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('mode');
});

it('lets a person pick how long their own activity is kept, and applies it straight away', function () {
    $me = actingAsRole(UserRole::BudgetOfficer);
    $old = footprint($me, ['created_at' => now()->subDays(100)]);
    $recent = footprint($me, ['created_at' => now()->subDays(5)]);

    $this->putJson('/api/v1/my-activity/settings', ['retention_days' => 90])
        ->assertOk()
        ->assertJsonPath('retention_days', 90)
        ->assertJsonPath('cleared', 1);

    expect($old->fresh()->hidden_at)->not->toBeNull()->and($recent->fresh()->hidden_at)->toBeNull();

    $this->putJson('/api/v1/my-activity/settings', ['retention_days' => 45])->assertUnprocessable()->assertJsonValidationErrors('retention_days');
    $this->putJson('/api/v1/my-activity/settings', [])->assertUnprocessable();

    $this->putJson('/api/v1/my-activity/settings', ['retention_days' => null])->assertOk()->assertJsonPath('retention_days', null);
    expect($me->fresh()->activity_retention_days)->toBeNull();
});

it('gives the administrator everyone’s activity with filters, and a list of people', function () {
    $admin = actingAsRole(UserRole::Admin);
    $ana = User::factory()->create(['name' => 'Ana Reyes']);
    $ben = User::factory()->create(['name' => 'Ben Cruz']);
    AuditLog::query()->delete(); // creating the users above left footprints of their own
    footprint($ana, ['action' => 'login', 'description' => 'Signed in to the system']);
    footprint($ben, ['action' => 'deleted', 'description' => 'Check 7 removed']);
    footprint($ben, ['created_at' => now()->subDays(20)]);

    $this->getJson('/api/v1/audit-logs?per_page=200')->assertOk()
        ->assertJsonPath('summary.total', 3)
        ->assertJsonPath('summary.people', 2);
    $this->getJson('/api/v1/audit-logs?user_id='.$ben->id)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit-logs?action=login')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.who', 'Ana Reyes');
    $this->getJson('/api/v1/audit-logs?search=Ben')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit-logs?date_to='.now()->subDays(10)->toDateString())->assertOk()->assertJsonCount(1, 'data');

    $people = collect($this->getJson('/api/v1/audit-logs/people')->assertOk()->json('data'));
    expect($people->pluck('name')->all())->toBe(['Ana Reyes', 'Ben Cruz'])
        ->and($people->pluck('role_label')->unique()->count())->toBeGreaterThan(0)
        ->and(array_keys($people->first()))->toContain('avatar_url', 'role_label');

    foreach ([UserRole::FinancialAnalyst, UserRole::BudgetOfficer, UserRole::DisbursingOfficer] as $role) {
        actingAsRole($role);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/audit-logs/people')->assertForbidden();
        $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'all', 'confirm' => 'CLEAR'])->assertForbidden();
        $this->putJson('/api/v1/audit-logs/settings', ['retention_days' => 90])->assertForbidden();
    }
    expect($admin)->not->toBeNull();
});

it('lets the administrator permanently delete entries, and leaves a trace of it', function () {
    $admin = actingAsRole(UserRole::Admin);
    $ana = User::factory()->create();
    $ben = User::factory()->create();
    $a1 = footprint($ana);
    $a2 = footprint($ana, ['created_at' => now()->subDays(60)]);
    $b1 = footprint($ben);

    $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'ids', 'ids' => [$a1->id]])->assertOk()->assertJsonPath('deleted', 1);
    $this->assertDatabaseMissing('audit_logs', ['id' => $a1->id]);

    $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'before', 'before' => now()->subDays(30)->toDateString()])->assertOk()->assertJsonPath('deleted', 1);
    $this->assertDatabaseMissing('audit_logs', ['id' => $a2->id]);

    $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'user', 'user_id' => $ben->id])->assertOk()->assertJsonPath('deleted', 1);
    $this->assertDatabaseMissing('audit_logs', ['id' => $b1->id]);

    // "Everything" needs an explicit confirmation word.
    $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'all'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson('/api/v1/audit-logs/clear', ['mode' => 'all', 'confirm' => 'CLEAR'])->assertOk();

    // What remains is only the record that the administrator did this.
    $left = AuditLog::query()->get();
    expect($left)->toHaveCount(1)
        ->and($left->first()->action)->toBe('system.audit_purged')
        ->and($left->first()->user_id)->toBe($admin->id);
});

it('lets the administrator set a system-wide retention, which deletes old entries', function () {
    actingAsRole(UserRole::Admin);
    $ana = User::factory()->create();
    $old = footprint($ana, ['created_at' => now()->subDays(200)]);
    $recent = footprint($ana, ['created_at' => now()->subDays(20)]);

    $this->getJson('/api/v1/audit-logs/settings')->assertOk()->assertJsonPath('retention_days', null);

    $this->putJson('/api/v1/audit-logs/settings', ['retention_days' => 100])->assertUnprocessable()->assertJsonValidationErrors('retention_days');

    $this->putJson('/api/v1/audit-logs/settings', ['retention_days' => 180])
        ->assertOk()
        ->assertJsonPath('retention_days', 180)
        ->assertJsonPath('purged', 1);

    $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
    $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    expect(SystemSetting::read('audit_retention_days'))->toBe('180');

    $this->putJson('/api/v1/audit-logs/settings', ['retention_days' => null])->assertOk()->assertJsonPath('retention_days', null);
});

it('runs both auto-clear schedules from the scheduled command', function () {
    $keeps = User::factory()->create(['activity_retention_days' => null]);
    $trims = User::factory()->create(['activity_retention_days' => 30]);

    $keepOld = footprint($keeps, ['created_at' => now()->subDays(100)]);
    $trimOld = footprint($trims, ['created_at' => now()->subDays(45)]);
    $trimNew = footprint($trims, ['created_at' => now()->subDays(3)]);

    $this->artisan('finann:activity-clean')->assertSuccessful();

    // Personal schedule: hidden from the person's list, audit copy kept.
    expect($trimOld->fresh()->hidden_at)->not->toBeNull()
        ->and($trimNew->fresh()->hidden_at)->toBeNull()
        ->and($keepOld->fresh()->hidden_at)->toBeNull();

    // System schedule: gone for good.
    SystemSetting::write('audit_retention_days', '365');
    $ancient = footprint($keeps, ['created_at' => now()->subDays(400)]);
    $this->artisan('finann:activity-clean')->assertSuccessful();
    $this->assertDatabaseMissing('audit_logs', ['id' => $ancient->id]);
    $this->assertDatabaseHas('audit_logs', ['id' => $trimOld->id]);
});

it('does not let a sign-in alone stop an account being deleted, but real work does', function () {
    $admin = actingAsRole(UserRole::Admin);
    $signedInOnly = User::factory()->role(UserRole::BudgetOfficer)->create();
    $worked = User::factory()->role(UserRole::BudgetOfficer)->create();

    footprint($signedInOnly, ['action' => 'login', 'description' => 'Signed in to the system']);
    footprint($worked, ['action' => 'deleted', 'description' => 'Check 9 removed']);

    expect($signedInOnly->hasActivityRecords())->toBeFalse()
        ->and($worked->hasActivityRecords())->toBeTrue();

    $this->deleteJson("/api/v1/users/{$signedInOnly->id}")->assertNoContent();
    $this->deleteJson("/api/v1/users/{$worked->id}")->assertUnprocessable();
    expect($admin)->not->toBeNull();
});

it('words reconciliation status changes and plain creations for people', function () {
    $me = actingAsRole(UserRole::Admin);
    $reconciliation = Reconciliation::factory()->create();
    AuditLog::query()->delete();

    footprint($me, ['action' => 'created', 'description' => 'BankAccount created', 'auditable_type' => 'App\Models\BankAccount', 'auditable_id' => 3, 'changes' => ['id' => 3, 'bank_name' => 'LBP']]);
    footprint($me, ['action' => 'updated', 'description' => 'Reconciliation updated', 'auditable_type' => 'App\Models\Reconciliation', 'auditable_id' => $reconciliation->id, 'changes' => ['status' => 'certified']]);

    $summaries = collect($this->getJson('/api/v1/my-activity')->json('data'))->pluck('summary')->all();
    expect($summaries)->toContain('Added bank account #3', "Moved reconciliation #{$reconciliation->id} to Certified");

    $created = collect($this->getJson('/api/v1/my-activity')->json('data'))->firstWhere('kind', 'created');
    expect($created['details'])->toBe([]); // a creation lists every column, which would only be noise
});
