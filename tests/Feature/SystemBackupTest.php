<?php

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Models\User;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->backupDir = storage_path('app/testing-backups-'.uniqid());
    File::deleteDirectory($this->backupDir);
    config(['backup.path' => $this->backupDir]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
    @unlink(storage_path('app/backup-schedule.json'));
});

it('lets an administrator download a fresh SQL backup but forbids other roles', function () {
    actingAsRole(UserRole::Admin);

    $response = $this->get('/api/v1/system/backup');
    $response->assertOk();

    $body = $response->streamedContent();
    expect($body)->toContain('SET FOREIGN_KEY_CHECKS=0')
        ->and($body)->toContain('INSERT INTO `users`')
        ->and($body)->toContain('FinAnnBankRec');

    actingAsRole(UserRole::BudgetOfficer);
    $this->get('/api/v1/system/backup')->assertForbidden();
});

it('lists creates downloads and deletes stored backups for the administrator', function () {
    actingAsRole(UserRole::Admin);

    $create = $this->postJson('/api/v1/system/backups')->assertCreated();
    $name = $create->json('backup.name');
    expect($name)->toEndWith('.sql')
        ->and($name)->toStartWith('finann-backup-');

    $this->getJson('/api/v1/system/backups')
        ->assertOk()
        ->assertJsonFragment(['name' => $name]);

    $this->get("/api/v1/system/backups/{$name}")->assertOk();

    $this->deleteJson("/api/v1/system/backups/{$name}")->assertOk();

    expect(app(DatabaseBackupService::class)->listFiles())->toBeEmpty();
});

it('clears reconciliation activity but keeps master data, and needs explicit confirmation', function () {
    actingAsRole(UserRole::Admin);

    $account = BankAccount::factory()->create();
    Reconciliation::factory()->for($account)->create();
    CheckIssuance::factory()->for($account)->create();
    $users = User::query()->count();

    $this->deleteJson('/api/v1/system/activity-data')->assertStatus(422);
    $this->deleteJson('/api/v1/system/activity-data', ['confirm' => 'nope'])->assertStatus(422);
    expect(Reconciliation::query()->count())->toBe(1);

    $this->deleteJson('/api/v1/system/activity-data', ['confirm' => 'CLEAR'])
        ->assertOk()
        ->assertJsonPath('deleted.reconciliations', 1)
        ->assertJsonPath('deleted.check_issuances', 1);

    expect(Reconciliation::query()->count())->toBe(0)
        ->and(CheckIssuance::query()->count())->toBe(0)
        ->and(BankAccount::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe($users);

    actingAsRole(UserRole::FinancialAnalyst);
    $this->deleteJson('/api/v1/system/activity-data', ['confirm' => 'CLEAR'])->assertForbidden();
});

it('lets the administrator update the backup schedule', function () {
    actingAsRole(UserRole::Admin);

    $this->putJson('/api/v1/system/backup-schedule', [
        'enabled' => true,
        'frequency' => 'weekly',
        'time' => '03:30',
        'weekday' => 2,
        'retention_days' => 14,
    ])->assertOk()
        ->assertJsonPath('schedule.frequency', 'weekly')
        ->assertJsonPath('schedule.time', '03:30')
        ->assertJsonPath('schedule.weekday', 2)
        ->assertJsonPath('schedule.retention_days', 14);

    $settings = app(BackupScheduleService::class)->get();
    expect($settings['enabled'])->toBeTrue()
        ->and($settings['frequency'])->toBe('weekly')
        ->and($settings['time'])->toBe('03:30');
});

it('lets the administrator change their password without changing email', function () {
    $admin = actingAsRole(UserRole::Admin);
    $email = $admin->email;

    $this->putJson('/api/v1/system/password', [
        'current_password' => 'wrong',
        'password' => 'new-secure-pass',
        'password_confirmation' => 'new-secure-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->putJson('/api/v1/system/password', [
        'current_password' => 'password',
        'password' => 'new-secure-pass',
        'password_confirmation' => 'new-secure-pass',
    ])->assertOk();

    expect($admin->fresh()->email)->toBe($email)
        ->and(Hash::check('new-secure-pass', $admin->fresh()->password))->toBeTrue();
});

it('exposes backup status for the administrator', function () {
    actingAsRole(UserRole::Admin);

    $this->getJson('/api/v1/system/status')
        ->assertOk()
        ->assertJsonStructure([
            'app',
            'backup' => ['directory', 'count', 'sql_count', 'schedule'],
        ]);
});
