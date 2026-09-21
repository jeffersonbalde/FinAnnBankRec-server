<?php

use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function fixtureUpload(string $name): UploadedFile
{
    return new UploadedFile(base_path("tests/fixtures/{$name}"), $name, null, null, true);
}

function draftReconciliation(): Reconciliation
{
    return Reconciliation::factory()->for(BankAccount::factory())->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);
}

beforeEach(fn () => Storage::fake('local'));

it('uploads and previews the Report of Checks Issued without committing', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $response = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'rci')
        ->assertJsonPath('data.status', 'parsed')
        ->assertJsonPath('data.valid_count', 2)
        ->assertJsonPath('data.error_count', 0);

    $this->assertDatabaseCount('check_issuances', 0);
});

it('commits an RCI import into check issuances', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ])->json('data.id');

    $this->postJson("/api/v1/imports/{$batchId}/commit")
        ->assertOk()
        ->assertJsonPath('data.status', 'committed')
        ->assertJsonPath('data.imported_count', 2);

    $this->assertDatabaseCount('check_issuances', 2);
    $this->assertDatabaseHas('check_issuances', [
        'bank_account_id' => $reconciliation->bank_account_id,
        'serial_no' => '000100001',
        'amount' => 10000.00,
        'status' => 'outstanding',
    ]);
});

it('commits a bank statement including the balance-forward marker', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'bank_statement',
        'file' => fixtureUpload('bank_statement.csv'),
    ])->json('data.id');

    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    $this->assertDatabaseCount('bank_transactions', 2);
    $this->assertDatabaseHas('bank_transactions', [
        'check_no' => '000100002',
        'debit' => 539000.00,
        'is_balance_forward' => false,
    ]);
    $this->assertDatabaseHas('bank_transactions', ['is_balance_forward' => true]);
});

it('takes the unadjusted bank balance from the committed statement and gives it back on removal', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();
    expect((float) $reconciliation->unadjusted_bank_balance)->toBe(0.0);

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'bank_statement',
        'file' => fixtureUpload('bank_statement.csv'),
    ])->json('data.id');

    // Previewing alone must not touch the reconciliation.
    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(0.0);

    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    // Ending running balance of the fixture statement.
    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(471000.0);

    $this->deleteJson("/api/v1/imports/{$batchId}")->assertNoContent();

    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(0.0);
});

it('keeps a bank balance the analyst typed themselves when the statement is removed', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'bank_statement',
        'file' => fixtureUpload('bank_statement.csv'),
    ])->json('data.id');
    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    $reconciliation->fresh()->update(['unadjusted_bank_balance' => 123456.78]);

    $this->deleteJson("/api/v1/imports/{$batchId}")->assertNoContent();

    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(123456.78);
});

it('never overwrites a bank balance the analyst already entered when a statement is committed', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();
    $reconciliation->update(['unadjusted_bank_balance' => 1_010_000]);

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'bank_statement',
        'file' => fixtureUpload('bank_statement.csv'),
    ])->json('data.id');
    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(1010000.0);
});

it('does not change the bank balance when only the Report of Checks Issued is imported', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ])->json('data.id');
    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    expect((float) $reconciliation->fresh()->unadjusted_bank_balance)->toBe(0.0);
});

it('can discard a committed import and its rows', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = draftReconciliation();

    $batchId = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ])->json('data.id');
    $this->postJson("/api/v1/imports/{$batchId}/commit")->assertOk();

    $this->deleteJson("/api/v1/imports/{$batchId}")->assertNoContent();

    $this->assertDatabaseCount('import_batches', 0);
    $this->assertDatabaseCount('check_issuances', 0);
});

it('rejects imports once the reconciliation is no longer editable', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = Reconciliation::factory()->for(BankAccount::factory())->create([
        'status' => ReconciliationStatus::Certified,
    ]);

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ])->assertStatus(422);
});

it('forbids the budget officer from creating or importing into reconciliations', function () {
    actingAsRole(UserRole::BudgetOfficer);

    // Read access is allowed for the reviewer...
    $this->getJson('/api/v1/reconciliations')->assertOk();

    // ...but preparation is not.
    $this->postJson('/api/v1/reconciliations', [])->assertForbidden();

    $reconciliation = draftReconciliation();
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => fixtureUpload('rci.xlsx'),
    ])->assertForbidden();
});
