<?php

use App\Enums\CheckStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

it('narrows the dashboard to a date range', function () {
    $account = BankAccount::factory()->create();
    outstandingCheck($account, 1000, '2026-08-10');
    outstandingCheck($account, 2000, '2026-09-10');
    outstandingCheck($account, 3000, '2026-09-20', CheckStatus::Cancelled);
    Reconciliation::factory()->for($account)->create(['status' => 'certified', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
    Reconciliation::factory()->for($account)->create(['status' => 'draft', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);

    actingAsRole(UserRole::BudgetOfficer);

    $this->getJson('/api/v1/dashboard?date_from=2026-09-01&date_to=2026-09-30')
        ->assertOk()
        ->assertJsonPath('filters.date_from', '2026-09-01')
        ->assertJsonPath('checks_issued_count', 1)
        ->assertJsonPath('checks_issued_amount', fn ($v) => (float) $v === 2000.0)
        ->assertJsonPath('outstanding_checks_count', 1)
        ->assertJsonPath('open_reconciliations', 1)
        ->assertJsonPath('certified', 0)
        ->assertJsonCount(1, 'balance_trend')
        ->assertJsonPath('per_fund.0.status', 'draft');

    // August only: the certified period, and no open ones.
    $this->getJson('/api/v1/dashboard?date_from=2026-08-01&date_to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('certified', 1)
        ->assertJsonPath('open_reconciliations', 0)
        ->assertJsonPath('per_fund.0.status', 'certified');

    // A range with nothing in it is simply empty.
    $this->getJson('/api/v1/dashboard?date_from=2025-01-01&date_to=2025-01-31')
        ->assertOk()
        ->assertJsonPath('checks_issued_count', 0)
        ->assertJsonCount(0, 'balance_trend')
        ->assertJsonPath('per_fund.0.status', null);

    // No dates at all means all time.
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('certified', 1)->assertJsonPath('checks_issued_count', 2);
});

it('narrows the dashboard to one bank account and rejects a backwards range', function () {
    $accountA = BankAccount::factory()->create();
    $accountB = BankAccount::factory()->create();
    outstandingCheck($accountA, 1000, '2026-09-10');
    outstandingCheck($accountB, 5000, '2026-09-11');

    actingAsRole(UserRole::FinancialAnalyst);

    $this->getJson('/api/v1/dashboard?bank_account_id='.$accountB->id)
        ->assertOk()
        ->assertJsonPath('outstanding_checks_count', 1)
        ->assertJsonPath('outstanding_checks_amount', fn ($v) => (float) $v === 5000.0)
        ->assertJsonCount(1, 'per_fund');

    $this->getJson('/api/v1/dashboard?date_from=2026-09-30&date_to=2026-09-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date_to');
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

/** Rows (as arrays) of the first sheet of an xlsx download, below the title block. */
function xlsxRows(string $binary): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $binary);
    $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    unlink($path);

    return $rows;
}

it('exports the outstanding checks list to Excel, oldest first, with age and a total', function () {
    $lbp = BankAccount::factory()->create(['bank_short_name' => 'LBP', 'account_number' => '1111-1111-11']);
    $dbp = BankAccount::factory()->create(['bank_short_name' => 'DBP', 'account_number' => '2222-2222-22']);

    outstandingCheck($lbp, 1000, now()->subDays(10)->toDateString());
    outstandingCheck($lbp, 2000, now()->subDays(200)->toDateString(), CheckStatus::Stale);
    outstandingCheck($dbp, 4000, now()->subDays(30)->toDateString());
    outstandingCheck($lbp, 9999, now()->subDays(5)->toDateString(), CheckStatus::Cleared);
    outstandingCheck($lbp, 8888, now()->subDays(5)->toDateString(), CheckStatus::Cancelled);

    foreach ([UserRole::FinancialAnalyst, UserRole::DisbursingOfficer, UserRole::BudgetOfficer, UserRole::Admin] as $role) {
        actingAsRole($role);
        $response = $this->get('/api/v1/outstanding-checks/export.xlsx');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
    }

    // Everything outstanding or stale — not the cleared or cancelled — oldest first.
    $rows = xlsxRows($this->get('/api/v1/outstanding-checks/export.xlsx')->streamedContent());
    $data = array_values(array_filter(array_slice($rows, 7), fn ($r) => $r[1] !== null));
    expect($data)->toHaveCount(3)
        ->and(array_column($data, 7))->toBe([2000.0, 4000.0, 1000.0])
        ->and($data[0][5])->toBe(200)
        ->and($data[0][6])->toBe('Stale')
        ->and(collect($rows)->last()[7])->toBe(7000.0);

    // The same filters as the screen: one account, stale only, a search.
    $lbpOnly = xlsxRows($this->get('/api/v1/outstanding-checks/export.xlsx?bank_account_id='.$lbp->id)->streamedContent());
    expect(collect($lbpOnly)->last()[7])->toBe(3000.0);

    $staleOnly = xlsxRows($this->get('/api/v1/outstanding-checks/export.xlsx?status=stale')->streamedContent());
    expect(collect($staleOnly)->last()[7])->toBe(2000.0);

    $searched = xlsxRows($this->get('/api/v1/outstanding-checks/export.xlsx?search=2222-2222')->streamedContent());
    expect(collect($searched)->last()[7])->toBe(4000.0);

    // Only outstanding / stale are valid status filters here.
    $this->getJson('/api/v1/outstanding-checks/export.xlsx?status=cleared')->assertUnprocessable()->assertJsonValidationErrors('status');
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
