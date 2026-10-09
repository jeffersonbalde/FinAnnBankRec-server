<?php

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;

function periodFor(BankAccount $account, ReconciliationStatus $status, string $start = '2026-07-01', string $end = '2026-07-31'): Reconciliation
{
    return Reconciliation::factory()->for($account)->create([
        'status' => $status,
        'period_start' => $start,
        'period_end' => $end,
    ]);
}

it('deletes draft and returned reconciliations in bulk and keeps certified ones', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $account = BankAccount::factory()->create();

    $draft = periodFor($account, ReconciliationStatus::Draft, '2026-05-01', '2026-05-31');
    $returned = periodFor($account, ReconciliationStatus::Returned, '2026-06-01', '2026-06-30');
    $certified = periodFor($account, ReconciliationStatus::Certified, '2026-07-01', '2026-07-31');

    $this->postJson('/api/v1/reconciliations/bulk-delete', ['ids' => [$draft->id, $returned->id, $certified->id, 999999]])
        ->assertOk()
        ->assertJsonPath('deleted', 2)
        ->assertJsonCount(2, 'skipped')
        ->assertJsonPath('skipped.0.id', $certified->id)
        ->assertJsonPath('skipped.1.reason', 'Already removed.');

    $this->assertDatabaseMissing('reconciliations', ['id' => $draft->id]);
    $this->assertDatabaseMissing('reconciliations', ['id' => $returned->id]);
    $this->assertDatabaseHas('reconciliations', ['id' => $certified->id]);
});

it('keeps the checks in the register and frees the ones its statement had cleared', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $account = BankAccount::factory()->create();
    $period = periodFor($account, ReconciliationStatus::Draft);

    $transaction = BankTransaction::factory()->create(['bank_account_id' => $account->id, 'reconciliation_id' => $period->id]);
    $cleared = CheckIssuance::factory()->for($account)->create([
        'reconciliation_id' => $period->id,
        'status' => CheckStatus::Cleared,
        'cleared_on' => '2026-07-10',
        'cleared_bank_transaction_id' => $transaction->id,
    ]);
    $outstanding = CheckIssuance::factory()->for($account)->create([
        'reconciliation_id' => $period->id,
        'status' => CheckStatus::Outstanding,
    ]);

    $this->deleteJson("/api/v1/reconciliations/{$period->id}")->assertNoContent();

    $this->assertDatabaseMissing('reconciliations', ['id' => $period->id]);
    $this->assertDatabaseMissing('bank_transactions', ['id' => $transaction->id]);

    // Both checks are still in the register; the one the statement cleared is outstanding again.
    expect($outstanding->fresh()->reconciliation_id)->toBeNull()
        ->and($cleared->fresh()->status)->toBe(CheckStatus::Outstanding)
        ->and($cleared->fresh()->cleared_bank_transaction_id)->toBeNull()
        ->and($cleared->fresh()->cleared_on)->toBeNull();
});

it('tells the register which checks sit in a locked period, and why', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $account = BankAccount::factory()->create();
    $open = periodFor($account, ReconciliationStatus::Draft, '2026-08-01', '2026-08-31');
    $certified = periodFor($account, ReconciliationStatus::Certified);

    $inOpen = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => $open->id, 'status' => CheckStatus::Outstanding]);
    $inCertified = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => $certified->id, 'status' => CheckStatus::Outstanding]);
    $unassigned = CheckIssuance::factory()->for($account)->create(['reconciliation_id' => null, 'status' => CheckStatus::Outstanding]);

    $rows = collect($this->getJson('/api/v1/check-register?per_page=100')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$inOpen->id]['period_locked'])->toBeFalse()
        ->and($rows[$inOpen->id]['lock_reason'])->toBeNull()
        ->and($rows[$unassigned->id]['period_locked'])->toBeFalse()
        ->and($rows[$inCertified->id]['period_locked'])->toBeTrue()
        ->and($rows[$inCertified->id]['lock_reason'])->toContain('Jul 1, 2026 – Jul 31, 2026')->toContain('Certified and locked');

    // The same plain-words reason comes back when someone tries anyway.
    $this->deleteJson("/api/v1/check-issuances/{$inCertified->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', $rows[$inCertified->id]['lock_reason']);
    $this->postJson('/api/v1/check-register/bulk-delete', ['ids' => [$inCertified->id]])
        ->assertJsonPath('skipped.0.reason', $rows[$inCertified->id]['lock_reason']);
});

it('will not delete a certified reconciliation, singly or in bulk', function () {
    actingAsRole(UserRole::Admin);
    $certified = periodFor(BankAccount::factory()->create(), ReconciliationStatus::Certified);

    $this->deleteJson("/api/v1/reconciliations/{$certified->id}")->assertUnprocessable();
    $this->postJson('/api/v1/reconciliations/bulk-delete', ['ids' => [$certified->id]])
        ->assertOk()
        ->assertJsonPath('deleted', 0)
        ->assertJsonCount(1, 'skipped');
    $this->assertDatabaseHas('reconciliations', ['id' => $certified->id]);
});

it('validates and protects the bulk reconciliation removal', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $this->postJson('/api/v1/reconciliations/bulk-delete', ['ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->postJson('/api/v1/reconciliations/bulk-delete', ['ids' => range(1, 101)])->assertUnprocessable()->assertJsonValidationErrors('ids');

    $draft = periodFor(BankAccount::factory()->create(), ReconciliationStatus::Draft);

    foreach ([UserRole::DisbursingOfficer, UserRole::BudgetOfficer] as $role) {
        actingAsRole($role);
        $this->postJson('/api/v1/reconciliations/bulk-delete', ['ids' => [$draft->id]])->assertForbidden();
    }
    $this->assertDatabaseHas('reconciliations', ['id' => $draft->id]);
});
