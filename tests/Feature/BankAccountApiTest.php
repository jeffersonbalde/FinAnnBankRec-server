<?php

use App\Enums\SignatoryBlock;
use App\Enums\UserRole;
use App\Models\BankAccount;

function bankAccountPayload(array $overrides = []): array
{
    return array_merge([
        'bank_name' => 'Land Bank of the Philippines',
        'bank_short_name' => 'LBP',
        'account_number' => '1292-0001-01',
        'account_name' => 'TESDA',
        'entity_name' => 'TESDA-Mis. Occ.',
        'fund_cluster' => '101-MOOE',
        'is_active' => true,
        'signatories' => [
            [
                'block' => SignatoryBlock::PreparedBy->value,
                'name' => 'JOE ANN D. NISNISAN',
                'designation' => 'Administrative Officer IV / Financial Analyst',
            ],
            [
                'block' => SignatoryBlock::CertifiedCorrect->value,
                'name' => 'FERNANDO M. MANLARAN',
                'designation' => 'Administrative Assistant III',
            ],
            [
                'block' => SignatoryBlock::DisbursingOfficer->value,
                'name' => 'FERNANDO M. MANLARAN',
                'designation' => 'Disbursing Officer',
            ],
        ],
    ], $overrides);
}

it('lets an administrator list, create, update and delete bank accounts', function () {
    actingAsRole(UserRole::Admin);

    $this->getJson('/api/v1/bank-accounts')->assertOk()->assertJsonCount(0, 'data');

    $created = $this->postJson('/api/v1/bank-accounts', bankAccountPayload())
        ->assertCreated()
        ->assertJsonPath('data.account_number', '1292-0001-01')
        ->assertJsonCount(3, 'data.signatories');

    $id = $created->json('data.id');
    $this->assertDatabaseCount('signatories', 3);

    $this->putJson("/api/v1/bank-accounts/{$id}", [
        'bank_name' => 'Land Bank of the Philippines',
        'bank_short_name' => 'LBP',
        'account_number' => '1292-0001-01',
        'account_name' => 'TESDA Provincial Office',
        'entity_name' => 'TESDA-Mis. Occ.',
        'fund_cluster' => '101-GF',
        'is_active' => true,
    ])->assertOk()->assertJsonPath('data.fund_cluster', '101-GF');

    $this->deleteJson("/api/v1/bank-accounts/{$id}")->assertNoContent();
    $this->assertDatabaseCount('bank_accounts', 0);
    $this->assertDatabaseCount('signatories', 0);
});

it('requires signatories when creating a bank account', function () {
    actingAsRole(UserRole::Admin);

    $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['signatories' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('signatories');
});

it('rejects a duplicate account number', function () {
    actingAsRole(UserRole::Admin);
    BankAccount::factory()->create(['account_number' => '1292-0001-01']);

    $this->postJson('/api/v1/bank-accounts', bankAccountPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_number');
});

it('lets the financial analyst list bank accounts to prepare a reconciliation, but not write them', function () {
    actingAsRole(UserRole::FinancialAnalyst);

    $this->getJson('/api/v1/bank-accounts')->assertOk();
    $this->postJson('/api/v1/bank-accounts', [])->assertForbidden();
});

it('forbids other non-admin roles from master data entirely', function () {
    actingAsRole(UserRole::BudgetOfficer);

    $this->getJson('/api/v1/bank-accounts')->assertForbidden();
    $this->postJson('/api/v1/bank-accounts', [])->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/bank-accounts')->assertUnauthorized();
});

it('manages signatories nested under a bank account', function () {
    actingAsRole(UserRole::Admin);
    $account = BankAccount::factory()->create();

    $signatory = $this->postJson("/api/v1/bank-accounts/{$account->id}/signatories", [
        'block' => SignatoryBlock::PreparedBy->value,
        'name' => 'Joe Ann D. Nisnisan',
        'designation' => 'Administrative Officer IV',
        'sort_order' => 1,
    ])->assertCreated()->assertJsonPath('data.block_label', 'Prepared by');

    $sigId = $signatory->json('data.id');

    $this->putJson("/api/v1/signatories/{$sigId}", [
        'block' => SignatoryBlock::PreparedBy->value,
        'name' => 'Joe Ann D. Nisnisan',
        'designation' => 'AO IV / Financial Analyst',
    ])->assertOk()->assertJsonPath('data.designation', 'AO IV / Financial Analyst');

    $this->deleteJson("/api/v1/signatories/{$sigId}")->assertNoContent();
    $this->assertDatabaseCount('signatories', 0);
});
