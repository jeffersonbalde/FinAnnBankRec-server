<?php

use App\Enums\CheckStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;

function outstandingCheck(BankAccount $account, float $amount, string $date, CheckStatus $status = CheckStatus::Outstanding): CheckIssuance
{
    return CheckIssuance::create([
        'bank_account_id' => $account->id,
        'serial_no' => (string) fake()->unique()->numerify('#########'),
        'payee' => fake()->company(),
        'amount' => $amount,
        'check_date' => $date,
        'status' => $status,
    ]);
}

it('summarises the dashboard for any signed-in user', function () {
    $account = BankAccount::factory()->create();
    outstandingCheck($account, 10000, now()->subDays(10)->toDateString());
    outstandingCheck($account, 5000, now()->subDays(200)->toDateString(), CheckStatus::Stale);
    Reconciliation::factory()->for($account)->create(['status' => 'draft']);
    Reconciliation::factory()->create(['status' => 'certified']);

    actingAsRole(UserRole::DisbursingOfficer);

    $this->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('outstanding_checks_count', 2)
        ->assertJsonPath('outstanding_checks_amount', fn ($v) => (float) $v === 15000.0)
        ->assertJsonPath('stale_checks_count', 1)
        ->assertJsonPath('open_reconciliations', 1)
        ->assertJsonPath('aging.0-30.count', 1)
        ->assertJsonPath('aging.180+.count', 1)
        ->assertJsonCount(4, 'status_breakdown')
        ->assertJsonCount(2, 'balance_trend');
});

it('lists the outstanding-check register with aging', function () {
    $account = BankAccount::factory()->create();
    outstandingCheck($account, 1200, now()->subDays(45)->toDateString());

    actingAsRole(UserRole::FinancialAnalyst);

    $this->getJson('/api/v1/outstanding-checks')
        ->assertOk()
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('summary.amount', fn ($v) => (float) $v === 1200.0)
        ->assertJsonPath('data.0.days_outstanding', 45);
});

it('filters the outstanding-check register by bank account and search', function () {
    $accountA = BankAccount::factory()->create([
        'bank_short_name' => 'LBP',
        'account_number' => '1292-0001-01',
        'fund_cluster' => '101-MOOE',
    ]);
    $accountB = BankAccount::factory()->create([
        'bank_short_name' => 'DBP',
        'account_number' => '0077-0123-45',
        'fund_cluster' => '102-PS',
    ]);

    CheckIssuance::create([
        'bank_account_id' => $accountA->id,
        'serial_no' => '000100001',
        'payee' => 'JOHN D. DELOS SANTOS',
        'amount' => 1500,
        'check_date' => now()->subDays(20)->toDateString(),
        'status' => CheckStatus::Outstanding,
    ]);
    CheckIssuance::create([
        'bank_account_id' => $accountB->id,
        'serial_no' => '000200099',
        'payee' => 'XYZ SCHOOL',
        'amount' => 2500,
        'check_date' => now()->subDays(30)->toDateString(),
        'status' => CheckStatus::Outstanding,
    ]);

    actingAsRole(UserRole::FinancialAnalyst);

    $this->getJson('/api/v1/outstanding-checks?bank_account_id='.$accountA->id)
        ->assertOk()
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('summary.amount', fn ($v) => (float) $v === 1500.0)
        ->assertJsonPath('data.0.serial_no', '000100001')
        ->assertJsonPath('data.0.bank_account_id', $accountA->id);

    $this->getJson('/api/v1/outstanding-checks?search=XYZ')
        ->assertOk()
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('data.0.serial_no', '000200099');

    $this->getJson('/api/v1/outstanding-checks?search=0077-0123')
        ->assertOk()
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('data.0.bank_account_id', $accountB->id);

    $this->getJson('/api/v1/outstanding-checks?bank_account_id='.$accountA->id.'&search=XYZ')
        ->assertOk()
        ->assertJsonPath('summary.count', 0)
        ->assertJsonCount(0, 'data');
});

it('records audit entries and exposes them to the admin only', function () {
    $admin = actingAsRole(UserRole::Admin);

    // Creating a bank account through the API leaves an audit trail.
    $this->postJson('/api/v1/bank-accounts', [
        'bank_name' => 'LBP',
        'bank_short_name' => 'LBP',
        'account_number' => '9999-0000-01',
        'account_name' => 'TESDA',
        'entity_name' => 'TESDA-Mis. Occ.',
        'fund_cluster' => '101-MOOE',
        'signatories' => [
            [
                'block' => 'prepared_by',
                'name' => 'Prepared Person',
                'designation' => 'AO IV',
            ],
            [
                'block' => 'certified_correct',
                'name' => 'Certifier',
                'designation' => 'AA III',
            ],
            [
                'block' => 'disbursing_officer',
                'name' => 'Disbursing',
                'designation' => 'Disbursing Officer',
            ],
        ],
    ])->assertCreated();

    $this->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'created')
        ->assertJsonPath('data.0.who', $admin->name);

    actingAsRole(UserRole::FinancialAnalyst);
    $this->getJson('/api/v1/audit-logs')->assertForbidden();
});
