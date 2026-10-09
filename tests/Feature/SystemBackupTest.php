<?php

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Models\User;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->backupDir = storage_path('app/testing-backups-'.uniqid());
    File::deleteDirectory($this->backupDir);
    config(['backup.path' => $this->backupDir]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
    @unlink((string) config('backup.settings_path'));
});

it('lets an administrator download a fresh SQL backup but forbids other roles', function () {
    actingAsRole(UserRole::Admin);

    $response = $this->get('/api/v1/system/backup');
    $response->assertOk();

    $body = $response->streamedContent();
    expect($body)->toContain('SET FOREIGN_KEY_CHECKS=0')
        ->and($body)->toContain('INSERT INTO `users`')
        ->and($body)->toContain((string) config('app.name'));

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

it('saves backups in the folder the administrator chooses and lists them from there', function () {
    actingAsRole(UserRole::Admin);
    $folder = storage_path('app/testing-custom-'.uniqid());

    $this->putJson('/api/v1/system/backup-folder', ['directory' => $folder])
        ->assertOk()
        ->assertJsonPath('folder.path', str_replace('/', DIRECTORY_SEPARATOR, $folder))
        ->assertJsonPath('folder.is_default', false)
        ->assertJsonPath('folder.problem', null);

    $name = $this->postJson('/api/v1/system/backups')->assertCreated()->json('backup.name');

    expect(is_file($folder.DIRECTORY_SEPARATOR.$name))->toBeTrue()
        ->and(is_file($this->backupDir.DIRECTORY_SEPARATOR.$name))->toBeFalse();

    $this->getJson('/api/v1/system/backups')->assertJsonFragment(['name' => $name])->assertJsonPath('folder.path', str_replace('/', DIRECTORY_SEPARATOR, $folder));
    $this->get("/api/v1/system/backups/{$name}")->assertOk();

    // An empty folder goes back to the built-in one.
    $this->putJson('/api/v1/system/backup-folder', ['directory' => null])
        ->assertOk()
        ->assertJsonPath('folder.is_default', true)
        ->assertJsonPath('folder.path', str_replace('/', DIRECTORY_SEPARATOR, $this->backupDir));

    File::deleteDirectory($folder);
});

it('can bring the existing backups along when the folder changes', function () {
    actingAsRole(UserRole::Admin);
    $folder = storage_path('app/testing-moved-'.uniqid());

    $name = $this->postJson('/api/v1/system/backups')->assertCreated()->json('backup.name');

    $this->putJson('/api/v1/system/backup-folder', ['directory' => $folder, 'move_existing' => true])
        ->assertOk()
        ->assertJsonPath('moved', 2); // the .sql and its .json companion

    expect(is_file($folder.DIRECTORY_SEPARATOR.$name))->toBeTrue()
        ->and(is_file($this->backupDir.DIRECTORY_SEPARATOR.$name))->toBeFalse();

    File::deleteDirectory($folder);
});

it('rejects a backup folder that is not a usable full path', function () {
    actingAsRole(UserRole::Admin);

    foreach (['backups', '../backups', public_path('backups'), base_path('composer.json')] as $bad) {
        $this->putJson('/api/v1/system/backup-folder', ['directory' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('directory');
    }

    expect(app(BackupScheduleService::class)->get()['directory'])->toBeNull();
});

it('deletes several backups at once, with their companions, and reports the ones already gone', function () {
    actingAsRole(UserRole::Admin);
    $names = collect(['20260101-010101', '20260102-010101', '20260103-010101'])->map(function (string $stamp) {
        File::ensureDirectoryExists($this->backupDir);
        file_put_contents($this->backupDir.DIRECTORY_SEPARATOR."finann-backup-{$stamp}.sql", '-- x');
        file_put_contents($this->backupDir.DIRECTORY_SEPARATOR."finann-backup-{$stamp}.json", '{}');

        return "finann-backup-{$stamp}.sql";
    });

    $response = $this->postJson('/api/v1/system/backups/bulk-delete', [
        'ids' => [$names[0], $names[1], 'finann-backup-20250101-000000.sql'],
    ])->assertOk()->assertJsonPath('deleted', 2);

    expect($response->json('skipped.0.id'))->toBe('finann-backup-20250101-000000.sql')
        ->and(app(DatabaseBackupService::class)->listFiles())->toHaveCount(2) // the survivor's .sql and .json
        ->and(is_file($this->backupDir.DIRECTORY_SEPARATOR.'finann-backup-20260101-010101.json'))->toBeFalse();

    // Only real backup names are accepted.
    $this->postJson('/api/v1/system/backups/bulk-delete', ['ids' => ['../../.env']])->assertStatus(422);
    $this->postJson('/api/v1/system/backups/bulk-delete', ['ids' => []])->assertStatus(422);

    actingAsRole(UserRole::BudgetOfficer);
    $this->postJson('/api/v1/system/backups/bulk-delete', ['ids' => [$names[2]]])->assertForbidden();
    expect(is_file($this->backupDir.DIRECTORY_SEPARATOR.$names[2]))->toBeTrue();
});

it('removes backups older than the chosen number of days after a new backup, or keeps all when set to 0', function () {
    actingAsRole(UserRole::Admin);
    File::ensureDirectoryExists($this->backupDir);
    $old = $this->backupDir.DIRECTORY_SEPARATOR.'finann-backup-20250101-000000.sql';
    file_put_contents($old, '-- old');
    touch($old, now()->subDays(40)->getTimestamp());

    $schedule = ['enabled' => true, 'frequency' => 'daily', 'time' => '02:00'];

    $this->putJson('/api/v1/system/backup-schedule', $schedule + ['retention_days' => 0])->assertOk();
    $this->postJson('/api/v1/system/backups')->assertCreated();
    expect(is_file($old))->toBeTrue();

    $this->putJson('/api/v1/system/backup-schedule', $schedule + ['retention_days' => 30])->assertOk();
    $this->postJson('/api/v1/system/backups')->assertCreated();
    expect(is_file($old))->toBeFalse();
});

it('lets the administrator browse folders and create a new one in the folder picker', function () {
    actingAsRole(UserRole::Admin);
    $root = storage_path('app/testing-browse-'.uniqid());
    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'Beta');
    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'alpha');
    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.hidden');
    file_put_contents($root.DIRECTORY_SEPARATOR.'a-file.txt', 'x');

    // Folders only, hidden ones left out, sorted by name.
    $listing = $this->getJson('/api/v1/system/backup-folders?path='.urlencode($root))->assertOk();
    expect(collect($listing->json('folders'))->pluck('name')->all())->toBe(['alpha', 'Beta'])
        ->and($listing->json('writable'))->toBeTrue()
        ->and($listing->json('parent'))->not->toBeNull();

    $this->postJson('/api/v1/system/backup-folders', ['parent' => $root, 'name' => 'Backups 2026'])
        ->assertCreated();
    expect(is_dir($root.DIRECTORY_SEPARATOR.'Backups 2026'))->toBeTrue();

    // Bad or duplicate names are refused with a message.
    $this->postJson('/api/v1/system/backup-folders', ['parent' => $root, 'name' => 'Backups 2026'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/system/backup-folders', ['parent' => $root, 'name' => 'a/b'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->getJson('/api/v1/system/backup-folders?path='.urlencode($root.DIRECTORY_SEPARATOR.'nope'))->assertStatus(422);
    $this->getJson('/api/v1/system/backup-folders?path=relative')->assertStatus(422);

    // The top of the picker: drives on Windows, the root elsewhere.
    $top = $this->getJson('/api/v1/system/backup-folders')->assertOk();
    expect($top->json('folders'))->not->toBeEmpty();

    actingAsRole(UserRole::FinancialAnalyst);
    $this->getJson('/api/v1/system/backup-folders')->assertForbidden();
    $this->putJson('/api/v1/system/backup-folder', ['directory' => $root])->assertForbidden();

    File::deleteDirectory($root);
});

it('finds the full path of a folder picked in the browser from its name and marker file', function () {
    actingAsRole(UserRole::Admin);
    $name = 'FABReS-locate-'.uniqid();
    // Inside the user's profile, where people really keep their folders (found first).
    $base = PHP_OS_FAMILY === 'Windows' ? (string) getenv('USERPROFILE') : storage_path('app');
    $folder = $base.DIRECTORY_SEPARATOR.$name;
    $marker = '.fabres-check-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($folder);
    file_put_contents($folder.DIRECTORY_SEPARATOR.$marker, '');

    $service = app(DatabaseBackupService::class);

    // The right name without the marker, or a bad marker, never matches.
    expect($service->locateFolder($name, '.fabres-check-'.bin2hex(random_bytes(8)), 2))->toBeNull()
        ->and($service->locateFolder($name, 'passwd', 2))->toBeNull()
        ->and($service->locateFolder('..', $marker, 2))->toBeNull();

    if (PHP_OS_FAMILY === 'Windows') {
        expect($service->locateFolder($name, $marker, 25))->not->toBeNull()
            ->and(realpath((string) $service->locateFolder($name, $marker, 25)))->toBe(realpath($folder));
    }

    $this->postJson('/api/v1/system/backup-folders/locate', ['name' => $name, 'marker' => 'nope'])->assertStatus(422);

    actingAsRole(UserRole::FinancialAnalyst);
    $this->postJson('/api/v1/system/backup-folders/locate', ['name' => $name, 'marker' => $marker])->assertForbidden();

    File::deleteDirectory($folder);
});

it('falls back to the default folder when the chosen one disappears', function () {
    actingAsRole(UserRole::Admin);
    // A path under a file can never be created, like a drive that was unplugged.
    app(BackupScheduleService::class)->save(['directory' => base_path('composer.json').DIRECTORY_SEPARATOR.'gone']);

    $this->getJson('/api/v1/system/backups')
        ->assertOk()
        ->assertJsonPath('folder.path', str_replace('/', DIRECTORY_SEPARATOR, $this->backupDir))
        ->assertJsonPath('folder.is_default', false);
    expect($this->getJson('/api/v1/system/backups')->json('folder.problem'))->toBeString();

    $this->postJson('/api/v1/system/backups')->assertCreated();
    expect(app(DatabaseBackupService::class)->listFiles())->not->toBeEmpty();
});

it('runs a missed scheduled backup as soon as the server is back, once per day', function () {
    $schedule = app(BackupScheduleService::class);
    $schedule->save(['enabled' => true, 'frequency' => 'daily', 'time' => '02:00']);

    $before = Carbon::parse('2026-10-09 01:59', config('app.timezone'));
    $late = Carbon::parse('2026-10-09 08:15', config('app.timezone'));

    expect($schedule->isDue($before))->toBeFalse()
        ->and($schedule->isDue($late))->toBeTrue();

    $schedule->runIfDue(app(DatabaseBackupService::class), $late);

    expect($schedule->isDue($late->copy()->addHour()))->toBeFalse()
        ->and($schedule->isDue($late->copy()->addDay()))->toBeTrue()
        ->and(app(DatabaseBackupService::class)->listFiles())->not->toBeEmpty();
});

it('does not back up right away when a schedule is saved after its time today', function () {
    actingAsRole(UserRole::Admin);
    $this->travelTo(Carbon::parse('2026-10-09 15:00', config('app.timezone')));

    $this->putJson('/api/v1/system/backup-schedule', ['enabled' => true, 'frequency' => 'daily', 'time' => '02:00', 'retention_days' => 30])
        ->assertOk();

    expect(app(BackupScheduleService::class)->isDue())->toBeFalse();
    $this->travelBack();
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
