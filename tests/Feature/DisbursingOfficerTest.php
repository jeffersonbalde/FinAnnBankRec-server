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

function doReconciliation(): Reconciliation
{
    return Reconciliation::factory()->for(BankAccount::factory())->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'unadjusted_bank_balance' => 1_010_000,
        'unadjusted_book_balance' => 1_000_000,
    ]);
}

function rciUpload(): UploadedFile
{
    return new UploadedFile(base_path('tests/fixtures/rci.xlsx'), 'rci.xlsx', null, null, true);
}

function stmtUpload(): UploadedFile
{
    return new UploadedFile(base_path('tests/fixtures/bank_statement.csv'), 'bank_statement.csv', null, null, true);
}

beforeEach(fn () => Storage::fake('local'));

it('lets the disbursing officer upload the Report of Checks Issued', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = doReconciliation();

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci',
        'file' => rciUpload(),
    ])->assertCreated()->assertJsonPath('data.type', 'rci');
});

it('blocks the disbursing officer from importing the bank statement', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = doReconciliation();

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'bank_statement',
        'file' => stmtUpload(),
    ])->assertForbidden();
});

it('lets the disbursing officer cancel an outstanding check and adds it back to the book', function () {
    $reconciliation = doReconciliation();
    $manager = app(ImportManager::class);
    $manager->commit($manager->parseUpload($reconciliation, ImportType::Rci, rciUpload(), null));

    $check = $reconciliation->checkIssuances()->where('serial_no', '000100001')->firstOrFail();

    actingAsRole(UserRole::DisbursingOfficer);

    $this->postJson("/api/v1/check-issuances/{$check->id}/cancel", [
        'reason' => 'Cheque spoiled during printing.',
    ])->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('check_issuances', [
        'id' => $check->id,
        'status' => 'cancelled',
        'cancelled_reason' => 'Cheque spoiled during printing.',
    ]);

    $this->assertDatabaseHas('reconciling_items', [
        'reconciliation_id' => $reconciliation->id,
        'category' => 'cancelled_check',
        'side' => 'book',
        'operation' => 'add',
        'amount' => 10000.00,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'App\Models\CheckIssuance',
        'auditable_id' => $check->id,
    ]);
});

it('will not cancel a check that has already cleared', function () {
    $reconciliation = doReconciliation();
    $check = CheckIssuance::factory()->for($reconciliation->bankAccount)->create([
        'reconciliation_id' => $reconciliation->id,
        'status' => CheckStatus::Cleared,
    ]);

    actingAsRole(UserRole::DisbursingOfficer);

    $this->postJson("/api/v1/check-issuances/{$check->id}/cancel", ['reason' => 'too late'])
        ->assertStatus(422);
});

it('forbids the budget officer from importing or cancelling', function () {
    $reconciliation = doReconciliation();
    $check = CheckIssuance::factory()->for($reconciliation->bankAccount)->create([
        'reconciliation_id' => $reconciliation->id,
        'status' => CheckStatus::Outstanding,
    ]);

    actingAsRole(UserRole::BudgetOfficer);

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/imports", [
        'type' => 'rci', 'file' => rciUpload(),
    ])->assertForbidden();

    $this->postJson("/api/v1/check-issuances/{$check->id}/cancel", ['reason' => 'nope'])
        ->assertForbidden();
});
