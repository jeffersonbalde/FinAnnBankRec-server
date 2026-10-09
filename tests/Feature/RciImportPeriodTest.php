<?php

use App\Enums\CheckStatus;
use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function accountWithPeriods(ReconciliationStatus $julyStatus = ReconciliationStatus::Draft): array
{
    $account = BankAccount::factory()->create(['fund_cluster' => '101-MOOE']);
    $july = Reconciliation::factory()->for($account)->create([
        'status' => $julyStatus,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);
    $october = Reconciliation::factory()->for($account)->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);

    return [$account, $july, $october];
}

function importRciInto(Reconciliation $reconciliation): void
{
    $manager = app(ImportManager::class);
    $upload = new UploadedFile(base_path('tests/fixtures/rci.xlsx'), 'rci.xlsx', null, null, true);
    $manager->commit($manager->parseUpload($reconciliation, ImportType::Rci, $upload, null));
}

beforeEach(fn () => Storage::fake('local'));

it('files imported checks under the period their date falls in, not the one they were uploaded into', function () {
    [, $july, $october] = accountWithPeriods();

    // The file holds checks dated in July, but is uploaded from the October reconciliation.
    importRciInto($october);

    $imported = CheckIssuance::query()->where('bank_account_id', $july->bank_account_id)->get();
    expect($imported)->not->toBeEmpty()
        ->and($imported->pluck('reconciliation_id')->unique()->all())->toBe([$july->id])
        ->and($october->checkIssuances()->count())->toBe(0);
});

it('leaves a certified period alone when a file is imported from a later one', function () {
    [$account, $july, $october] = accountWithPeriods(ReconciliationStatus::Certified);

    $existing = CheckIssuance::factory()->for($account)->create([
        'serial_no' => '000100001',
        'check_date' => '2026-07-01',
        'amount' => 1,
        'payee' => 'AS CERTIFIED',
        'status' => CheckStatus::Cleared,
        'reconciliation_id' => $july->id,
    ]);

    importRciInto($october);

    // The check that was already certified is untouched; none of the others are filed into July.
    expect($existing->fresh()->payee)->toBe('AS CERTIFIED')
        ->and($existing->fresh()->status)->toBe(CheckStatus::Cleared)
        ->and($existing->fresh()->reconciliation_id)->toBe($july->id)
        ->and($july->checkIssuances()->count())->toBe(1);
});

it('moves checks that were filed under the wrong period back to their own, leaving locked periods alone', function () {
    [$account, $july, $october] = accountWithPeriods();

    $misfiled = CheckIssuance::factory()->for($account)->create(['check_date' => '2026-07-20', 'reconciliation_id' => $october->id]);
    $rightlyIn = CheckIssuance::factory()->for($account)->create(['check_date' => '2026-10-05', 'reconciliation_id' => $october->id]);

    $certified = Reconciliation::factory()->for(BankAccount::factory()->create())->create([
        'status' => ReconciliationStatus::Certified,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);
    $inLocked = CheckIssuance::factory()->for($certified->bankAccount)->create(['check_date' => '2025-10-14', 'reconciliation_id' => $certified->id]);

    (require database_path('migrations/2026_10_08_200734_retag_checks_to_their_period.php'))->up();

    expect($misfiled->fresh()->reconciliation_id)->toBe($july->id)
        ->and($rightlyIn->fresh()->reconciliation_id)->toBe($october->id)
        ->and($inLocked->fresh()->reconciliation_id)->toBe($certified->id);
});

it('lists on the matching board only the checks that matter to the period, whatever month they came from', function () {
    $analyst = actingAsRole(UserRole::FinancialAnalyst);
    $account = BankAccount::factory()->create();
    $period = fn (string $start, string $end) => Reconciliation::factory()->for($account)->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => $start,
        'period_end' => $end,
    ]);
    $august = $period('2026-08-01', '2026-08-31');
    $october = $period('2026-10-01', '2026-10-31');

    $check = fn (string $serial, string $date, array $extra = []) => CheckIssuance::factory()->for($account)->create(array_merge([
        'serial_no' => $serial,
        'check_date' => $date,
        'status' => CheckStatus::Outstanding,
        'reconciliation_id' => null,
    ], $extra));

    $check('JUL-OUT', '2026-07-10');                                                    // carried over from July
    $check('AUG-OUT', '2026-08-20', ['reconciliation_id' => $august->id]);            // carried over from August
    $check('OCT-OUT', '2026-10-05', ['reconciliation_id' => $october->id]);           // issued this period
    $check('NOV-OUT', '2026-11-03');                                                    // not issued yet as of October 31
    $check('JUN-CLEARED', '2026-06-15', ['status' => CheckStatus::Cleared, 'cleared_on' => '2026-07-02']); // already cleared before October
    $check('SEP-CLEARED-OCT', '2026-09-20', ['status' => CheckStatus::Cleared, 'cleared_on' => '2026-10-04']); // cleared on October's statement

    $board = collect($this->getJson("/api/v1/reconciliations/{$october->id}/matches")->assertOk()->json('checks'));

    expect($board->pluck('serial_no')->sort()->values()->all())->toBe(['AUG-OUT', 'JUL-OUT', 'OCT-OUT', 'SEP-CLEARED-OCT'])
        ->and($board->firstWhere('serial_no', 'AUG-OUT')['origin_period_label'])->toBe('Aug 2026');
    expect($analyst)->not->toBeNull();
});

it('keeps the status of a check already on file when the report is imported again', function () {
    [$account, $july] = accountWithPeriods();

    $cleared = CheckIssuance::factory()->for($account)->create([
        'serial_no' => '000100001',
        'check_date' => '2026-07-01',
        'payee' => 'OLD PAYEE',
        'status' => CheckStatus::Cleared,
        'cleared_on' => '2026-07-09',
        'reconciliation_id' => $july->id,
    ]);
    $cancelled = CheckIssuance::factory()->for($account)->create([
        'serial_no' => '000100002',
        'check_date' => '2026-07-20',
        'status' => CheckStatus::Cancelled,
        'reconciliation_id' => $july->id,
    ]);

    importRciInto($july);

    // Details come from the file, but a cleared or cancelled check does not become outstanding again.
    expect($cleared->fresh()->status)->toBe(CheckStatus::Cleared)
        ->and($cleared->fresh()->cleared_on?->toDateString())->toBe('2026-07-09')
        ->and($cleared->fresh()->payee)->not->toBe('OLD PAYEE')
        ->and($cancelled->fresh()->status)->toBe(CheckStatus::Cancelled);
});

it('brings the open reconciliations up to date as soon as a report is imported or removed', function () {
    [$account, $july, $october] = accountWithPeriods();

    $manager = app(ImportManager::class);
    $upload = new UploadedFile(base_path('tests/fixtures/rci.xlsx'), 'rci.xlsx', null, null, true);
    $batch = $manager->commit($manager->parseUpload($october, ImportType::Rci, $upload, null));

    // Without anyone running matching: the new outstanding checks are already in both open periods.
    foreach ([$july, $october] as $period) {
        $this->assertDatabaseHas('reconciling_items', ['reconciliation_id' => $period->id, 'category' => 'outstanding_check']);
    }

    $manager->discard($batch);

    $this->assertDatabaseMissing('check_issuances', ['import_batch_id' => $batch->id]);
    foreach ([$july, $october] as $period) {
        $this->assertDatabaseMissing('reconciling_items', ['reconciliation_id' => $period->id, 'category' => 'outstanding_check']);
    }
});

it('removing an imported report takes back only the checks that are still outstanding', function () {
    [$account, $july] = accountWithPeriods();

    $manager = app(ImportManager::class);
    $upload = new UploadedFile(base_path('tests/fixtures/rci.xlsx'), 'rci.xlsx', null, null, true);
    $batch = $manager->commit($manager->parseUpload($july, ImportType::Rci, $upload, null));

    $kept = CheckIssuance::query()->where('import_batch_id', $batch->id)->firstOrFail();
    $kept->update(['status' => CheckStatus::Cleared, 'cleared_on' => '2026-07-10']);
    $total = CheckIssuance::query()->where('import_batch_id', $batch->id)->count();

    $manager->discard($batch);

    // The cleared one stays in the register (no longer tied to the removed file); the rest went with it.
    expect(CheckIssuance::query()->where('bank_account_id', $account->id)->count())->toBe(1)
        ->and($kept->fresh()->import_batch_id)->toBeNull()
        ->and($total)->toBeGreaterThan(1);
});
