<?php

use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function fixtureFile(string $name): UploadedFile
{
    return new UploadedFile(base_path("tests/fixtures/{$name}"), $name, null, null, true);
}

function julyReconciliation(): Reconciliation
{
    $account = BankAccount::factory()->create(['account_number' => '1292-0001-01', 'fund_cluster' => '101-MOOE']);

    return Reconciliation::factory()->for($account)->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'statement_label' => 'As of July 31, 2026',
        'unadjusted_bank_balance' => 1_010_000,
        'unadjusted_book_balance' => 1_000_000,
    ]);
}

function importFixture(Reconciliation $reconciliation, ImportType $type, string $file): void
{
    $manager = app(ImportManager::class);
    $batch = $manager->parseUpload($reconciliation, $type, fixtureFile($file), null);
    $manager->commit($batch);
}

beforeEach(fn () => Storage::fake('local'));

it('reproduces the July 2026 sample BRS from the real source files', function () {
    $reconciliation = julyReconciliation();

    importFixture($reconciliation, ImportType::Rci, 'rci.xlsx');
    importFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');

    $result = app(ReconciliationEngine::class)->run($reconciliation->fresh());

    // Check 000100002 cleared, 000100001 still outstanding.
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000100002', 'status' => 'cleared']);
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000100001', 'status' => 'outstanding']);
    expect($result['match']['matched'])->toBe(1)
        ->and($result['match']['outstanding'])->toBe(1)
        ->and($result['match']['flags'])->toBeEmpty();

    // Outstanding-checks reconciling item on the bank side, deducting 10,000.
    $this->assertDatabaseHas('reconciling_items', [
        'reconciliation_id' => $reconciliation->id,
        'category' => 'outstanding_check',
        'side' => 'bank',
        'operation' => 'deduct',
        'amount' => 10000.00,
        'schedule_no' => 'Schedule 1',
    ]);

    // BRS balances at 1,000,000 on both sides.
    $brs = $result['brs'];
    expect($brs['adjusted_bank_balance'])->toBe(1000000.0)
        ->and($brs['adjusted_book_balance'])->toBe(1000000.0)
        ->and($brs['difference'])->toBe(0.0)
        ->and($brs['is_balanced'])->toBeTrue();

    $reconciliation->refresh();
    expect((float) $reconciliation->difference)->toBe(0.0);
});

it('flags a check the bank cleared for a different amount', function () {
    $reconciliation = julyReconciliation();
    importFixture($reconciliation, ImportType::Rci, 'rci.xlsx');
    importFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');

    // Tamper with the book amount for the cleared check.
    $reconciliation->bankAccount->checkIssuances()
        ->where('serial_no', '000100002')
        ->update(['amount' => 540_000]);

    $result = app(ReconciliationEngine::class)->run($reconciliation->fresh());

    expect($result['match']['matched'])->toBe(0)
        ->and($result['match']['flags'])->toHaveCount(1)
        ->and($result['match']['flags'][0]['type'])->toBe('amount_mismatch');

    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000100002', 'status' => 'outstanding']);
});

it('exposes the BRS through the API and reflects a manual adjustment', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = julyReconciliation();
    importFixture($reconciliation, ImportType::Rci, 'rci.xlsx');
    importFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/match")
        ->assertOk()
        ->assertJsonPath('brs.is_balanced', true);

    // A ₱500 bank charge the analyst keys in by hand unbalances the statement.
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/reconciling-items", [
        'category' => 'error_overstating_book',
        'amount' => 500,
        'explanatory_comment' => 'Duplicate posting of DV 2026-07-0003.',
    ])->assertCreated();

    $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/brs")
        ->assertOk()
        ->assertJsonPath('brs.is_balanced', false)
        ->assertJsonPath('brs.adjusted_book_balance', fn ($v) => (float) $v === 999500.0);
});

it('marks checks older than six months as stale', function () {
    $reconciliation = julyReconciliation();
    importFixture($reconciliation, ImportType::Rci, 'rci.xlsx');

    $reconciliation->bankAccount->checkIssuances()
        ->where('serial_no', '000100001')
        ->update(['check_date' => '2025-11-30']); // > 6 months before 2026-07-31

    app(ReconciliationEngine::class)->run($reconciliation->fresh());

    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000100001', 'status' => 'stale']);
});
