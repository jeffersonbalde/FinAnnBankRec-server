<?php

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Models\ReferenceUacs;

function editableReconciliation(?BankAccount $account = null): Reconciliation
{
    return Reconciliation::factory()->for($account ?? BankAccount::factory()->create())->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);
}

function manualCheckPayload(BankAccount $account, array $overrides = []): array
{
    return array_merge([
        'bank_account_id' => $account->id,
        'check_date' => '2026-07-15',
        'serial_no' => '000200001',
        'dv_no' => 'DV-2026-07-001',
        'or_burs_no' => null,
        'responsibility_center_code' => '01-001',
        'payee' => 'Juan Dela Cruz',
        'uacs_object_code' => '5020101000',
        'nature_of_payment' => 'Salary',
        'amount' => 15000,
        'gross_taxable_amount' => 16000,
        'withholding_tax' => 1000,
        'report_no' => null,
        'notes' => null,
    ], $overrides);
}

it('lets the disbursing officer record a check in the register, tagged to who typed it', function () {
    $user = actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();

    $response = $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount))
        ->assertCreated();

    $response->assertJsonPath('data.serial_no', '000200001')
        ->assertJsonPath('data.status', 'outstanding')
        ->assertJsonPath('data.is_manual', true)
        ->assertJsonPath('data.created_by_name', $user->name);

    $this->assertDatabaseHas('check_issuances', [
        'bank_account_id' => $reconciliation->bank_account_id,
        // The July period already exists, so the check is tagged to it.
        'reconciliation_id' => $reconciliation->id,
        'serial_no' => '000200001',
        'import_batch_id' => null,
        'created_by' => $user->id,
    ]);
    $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'App\Models\CheckIssuance', 'action' => 'created']);
});

it('accepts amounts typed with thousands separators', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();

    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount, [
        'amount' => '₱1,234,567.50',
        'gross_taxable_amount' => '1,300,000.00',
        'withholding_tax' => '',
    ]))->assertCreated();

    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200001', 'amount' => '1234567.50', 'withholding_tax' => null]);

    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount, [
        'serial_no' => '000200002',
        'amount' => '0.00',
    ]))->assertUnprocessable()->assertJsonValidationErrors('amount');

    // Larger than the column can hold: a clear validation message, not a database error.
    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount, [
        'serial_no' => '000200003',
        'amount' => '99,999,999,999,999.99',
    ]))->assertUnprocessable()->assertJsonValidationErrors('amount');
});

it('updates the open reconciliation as soon as a check is recorded', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();

    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount))->assertCreated();

    $this->assertDatabaseHas('reconciling_items', [
        'reconciliation_id' => $reconciliation->id,
        'category' => 'outstanding_check',
        'amount' => 15000.00,
    ]);
});

it('hands a check recorded before its period exists to that period once it is created', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $account = BankAccount::factory()->create();

    $this->postJson('/api/v1/check-register', manualCheckPayload($account, ['check_date' => '2026-09-10']))
        ->assertCreated();
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200001', 'reconciliation_id' => null]);

    $september = Reconciliation::factory()->for($account)->create([
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
    ]);

    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200001', 'reconciliation_id' => $september->id]);
});

it('rejects a duplicate serial number on the same bank account but allows it on another', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();
    CheckIssuance::factory()->for($reconciliation->bankAccount)->create(['serial_no' => '000200001']);

    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount))
        ->assertStatus(422)
        ->assertJsonValidationErrors('serial_no');

    $this->postJson('/api/v1/check-register', manualCheckPayload(BankAccount::factory()->create()))
        ->assertCreated();
});

it('requires a date, because the date decides which period the check belongs to', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $account = BankAccount::factory()->create();

    $this->postJson('/api/v1/check-register', manualCheckPayload($account, ['check_date' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('check_date');
});

it('requires a bank account when recording a check', function () {
    actingAsRole(UserRole::DisbursingOfficer);

    $this->postJson('/api/v1/check-register', manualCheckPayload(BankAccount::factory()->make(['id' => 9999]), ['bank_account_id' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('bank_account_id');
});

it('will not record a check into a period that is already certified', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();
    $reconciliation->update(['status' => ReconciliationStatus::Certified]);

    $this->postJson('/api/v1/check-register', manualCheckPayload($reconciliation->bankAccount))
        ->assertStatus(422)
        ->assertJsonValidationErrors('check_date');
});

it('lets the disbursing officer edit an outstanding check, even one another user entered', function () {
    $account = BankAccount::factory()->create();
    $author = userWithRole(UserRole::FinancialAnalyst);
    $check = CheckIssuance::factory()->for($account)->create([
        'status' => CheckStatus::Outstanding,
        'amount' => 15000,
        'created_by' => $author->id,
    ]);

    $editor = actingAsRole(UserRole::DisbursingOfficer);

    $this->putJson("/api/v1/check-issuances/{$check->id}", manualCheckPayload($account, [
        'bank_account_id' => null,
        'serial_no' => $check->serial_no,
        'payee' => 'Corrected Payee',
        'amount' => 17500,
    ]))->assertStatus(422); // the account can't be changed once recorded

    $payload = manualCheckPayload($account, ['serial_no' => $check->serial_no, 'payee' => 'Corrected Payee', 'amount' => 17500]);
    unset($payload['bank_account_id']);

    $this->putJson("/api/v1/check-issuances/{$check->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.payee', 'Corrected Payee')
        ->assertJsonPath('data.created_by_name', $author->name)
        ->assertJsonPath('data.updated_by_name', $editor->name);

    $this->assertDatabaseHas('check_issuances', ['id' => $check->id, 'amount' => 17500.00]);
});

it('will not edit or delete a check that has already cleared', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $account = BankAccount::factory()->create();
    $check = CheckIssuance::factory()->for($account)->create(['status' => CheckStatus::Cleared]);

    $payload = manualCheckPayload($account, ['serial_no' => $check->serial_no]);
    unset($payload['bank_account_id']);

    $this->putJson("/api/v1/check-issuances/{$check->id}", $payload)->assertStatus(422);
    $this->deleteJson("/api/v1/check-issuances/{$check->id}")->assertStatus(422);
});

it('deletes a hand-entered check and an imported one, keeping the import count honest', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();

    $manual = CheckIssuance::factory()->for($reconciliation->bankAccount)->create([
        'reconciliation_id' => $reconciliation->id,
        'status' => CheckStatus::Outstanding,
        'import_batch_id' => null,
    ]);

    $this->deleteJson("/api/v1/check-issuances/{$manual->id}")->assertNoContent();
    $this->assertDatabaseMissing('check_issuances', ['id' => $manual->id]);

    $batch = ImportBatch::factory()->create([
        'reconciliation_id' => $reconciliation->id,
        'bank_account_id' => $reconciliation->bank_account_id,
        'type' => 'rci',
        'original_filename' => 'rci.xlsx',
        'status' => 'committed',
        'imported_count' => 5,
    ]);
    $imported = CheckIssuance::factory()->for($reconciliation->bankAccount)->create([
        'reconciliation_id' => $reconciliation->id,
        'status' => CheckStatus::Outstanding,
        'import_batch_id' => $batch->id,
    ]);

    $this->deleteJson("/api/v1/check-issuances/{$imported->id}")->assertNoContent();

    $this->assertDatabaseMissing('check_issuances', ['id' => $imported->id]);
    expect($batch->fresh()->imported_count)->toBe(4);
});

it('removes many outstanding checks at once and reports the ones it had to skip', function () {
    $user = actingAsRole(UserRole::DisbursingOfficer);
    $reconciliation = editableReconciliation();
    $account = $reconciliation->bankAccount;

    $batch = ImportBatch::factory()->create([
        'reconciliation_id' => $reconciliation->id,
        'bank_account_id' => $account->id,
        'type' => 'rci',
        'original_filename' => 'rci.xlsx',
        'status' => 'committed',
        'imported_count' => 3,
    ]);
    $typed = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => $reconciliation->id, 'status' => CheckStatus::Outstanding, 'import_batch_id' => null]);
    $imported = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => $reconciliation->id, 'status' => CheckStatus::Outstanding, 'import_batch_id' => $batch->id]);
    $cleared = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => $reconciliation->id, 'status' => CheckStatus::Cleared]);

    $locked = Reconciliation::factory()->for(BankAccount::factory()->create())->create(['status' => ReconciliationStatus::Certified]);
    $inLockedPeriod = CheckIssuance::factory()->for($locked->bankAccount)->create(['reconciliation_id' => $locked->id, 'status' => CheckStatus::Outstanding]);

    $this->postJson('/api/v1/check-register/bulk-delete', [
        'ids' => [$typed->id, $imported->id, $cleared->id, $inLockedPeriod->id, 999999],
    ])
        ->assertOk()
        ->assertJsonPath('deleted', 2)
        ->assertJsonCount(3, 'skipped')
        ->assertJsonPath('skipped.0.serial_no', $cleared->serial_no)
        ->assertJsonPath('skipped.2.reason', 'Already removed.');

    $this->assertDatabaseMissing('check_issuances', ['id' => $typed->id]);
    $this->assertDatabaseMissing('check_issuances', ['id' => $imported->id]);
    $this->assertDatabaseHas('check_issuances', ['id' => $cleared->id]);
    $this->assertDatabaseHas('check_issuances', ['id' => $inLockedPeriod->id]);
    expect($batch->fresh()->imported_count)->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $typed->id, 'action' => 'deleted', 'user_id' => $user->id]);
});

it('validates and protects the bulk removal', function () {
    actingAsRole(UserRole::DisbursingOfficer);
    $this->postJson('/api/v1/check-register/bulk-delete', ['ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->postJson('/api/v1/check-register/bulk-delete', ['ids' => range(1, 501)])->assertUnprocessable()->assertJsonValidationErrors('ids');

    $check = CheckIssuance::factory()->for(BankAccount::factory()->create())->create(['status' => CheckStatus::Outstanding]);

    actingAsRole(UserRole::BudgetOfficer);
    $this->postJson('/api/v1/check-register/bulk-delete', ['ids' => [$check->id]])->assertForbidden();
    $this->assertDatabaseHas('check_issuances', ['id' => $check->id]);
});

it('lists the register across bank accounts with filters, paging and totals', function () {
    actingAsRole(UserRole::BudgetOfficer);
    $one = BankAccount::factory()->create();
    $two = BankAccount::factory()->create();
    CheckIssuance::factory()->for($one)->create(['payee' => 'Alpha Corp', 'amount' => 1000, 'status' => CheckStatus::Outstanding, 'check_date' => '2026-07-05']);
    CheckIssuance::factory()->for($one)->create(['payee' => 'Beta Corp', 'amount' => 2000, 'status' => CheckStatus::Cleared, 'check_date' => '2026-07-06']);
    CheckIssuance::factory()->for($two)->create(['payee' => 'Gamma Corp', 'amount' => 4000, 'status' => CheckStatus::Cancelled, 'check_date' => '2026-08-01']);

    $this->getJson('/api/v1/check-register')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('summary.count', 3)
        ->assertJsonPath('summary.amount', fn ($v) => (float) $v === 3000.0)
        ->assertJsonPath('summary.outstanding_amount', fn ($v) => (float) $v === 1000.0);

    $this->getJson("/api/v1/check-register?bank_account_id={$one->id}&status=cleared")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payee', 'Beta Corp');

    $this->getJson('/api/v1/check-register?search=gamma')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/check-register?date_from=2026-08-01')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/check-register?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
});

it('gives every role that can see the register a bank-account picker', function () {
    BankAccount::factory()->create(['account_number' => '1111-2222-33']);
    BankAccount::factory()->create(['account_number' => '9999-0000-11', 'is_active' => false]);

    foreach ([UserRole::FinancialAnalyst, UserRole::DisbursingOfficer, UserRole::BudgetOfficer, UserRole::Admin] as $role) {
        actingAsRole($role);
        $this->getJson('/api/v1/check-register/bank-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', fn ($v) => str_contains($v, '1111-2222-33'));
    }
});

it('gives every role that can see the register the active UACS codes to pick from', function () {
    ReferenceUacs::factory()->create(['code' => '5020101000', 'description' => 'Traveling Expenses', 'is_active' => true]);
    ReferenceUacs::factory()->create(['code' => '9999999999', 'description' => 'Retired code', 'is_active' => false]);

    foreach ([UserRole::DisbursingOfficer, UserRole::BudgetOfficer] as $role) {
        actingAsRole($role);
        $this->getJson('/api/v1/check-register/uacs-codes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '5020101000');
    }
});

it('exports the register to Excel for every role that can see it', function () {
    CheckIssuance::factory()->for(BankAccount::factory()->create())->create();

    foreach ([UserRole::FinancialAnalyst, UserRole::DisbursingOfficer, UserRole::BudgetOfficer, UserRole::Admin] as $role) {
        actingAsRole($role);
        $response = $this->get('/api/v1/check-register/export.xlsx');
        $response->assertOk();
        expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
    }
});

it('lets the budget officer view the checks of a period, read-only', function () {
    actingAsRole(UserRole::BudgetOfficer);
    $reconciliation = editableReconciliation();
    CheckIssuance::factory()->for($reconciliation->bankAccount)->create(['reconciliation_id' => $reconciliation->id]);

    $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/check-issuances")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forbids the budget officer from recording or changing checks', function () {
    actingAsRole(UserRole::BudgetOfficer);
    $account = BankAccount::factory()->create();
    $check = CheckIssuance::factory()->for($account)->create();

    $this->postJson('/api/v1/check-register', manualCheckPayload($account))->assertForbidden();
    $this->deleteJson("/api/v1/check-issuances/{$check->id}")->assertForbidden();
});
