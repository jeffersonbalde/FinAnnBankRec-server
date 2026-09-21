<?php

use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Reconciliation;

it('paginates the reconciliation list', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    Reconciliation::factory()->count(15)->create();

    $first = $this->getJson('/api/v1/reconciliations?per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/v1/reconciliations?per_page=10&page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.current_page', 2);

    expect($first->json('data'))->toHaveCount(10);
});

it('caps per_page at 100', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    Reconciliation::factory()->count(3)->create();

    $this->getJson('/api/v1/reconciliations?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('searches reconciliations by bank account and statement label', function () {
    actingAsRole(UserRole::FinancialAnalyst);

    $landbank = BankAccount::factory()->create(['account_number' => '1292-0001-01']);
    $other = BankAccount::factory()->create(['account_number' => '5555-5555-55']);
    $match = Reconciliation::factory()->for($landbank, 'bankAccount')->create();
    $unrelated = Reconciliation::factory()->for($other, 'bankAccount')->create(['statement_label' => 'As of December 31, 2025']);

    $this->getJson('/api/v1/reconciliations?search=1292-0001')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);

    $this->getJson('/api/v1/reconciliations?search=December')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unrelated->id);

    $this->getJson('/api/v1/reconciliations?search=no-such-thing')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters reconciliations by status', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    Reconciliation::factory()->status(ReconciliationStatus::Draft)->count(2)->create();
    Reconciliation::factory()->status(ReconciliationStatus::Certified)->create();

    $this->getJson('/api/v1/reconciliations?status=certified')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
