<?php

use App\Services\Imports\BankStatementImportParser;
use App\Services\Imports\RciImportParser;

function fixture(string $name): string
{
    return __DIR__.'/../fixtures/'.$name;
}

it('parses the Report of Checks Issued (Appendix 35)', function () {
    $result = (new RciImportParser)->parse(fixture('rci.xlsx'));

    expect($result->rows)->toHaveCount(2)
        ->and($result->errors)->toBeEmpty();

    $first = collect($result->rows)->firstWhere('serial_no', '000100001');
    expect($first)->not->toBeNull()
        ->and($first['amount'])->toBe(10000.0)
        ->and($first['payee'])->toBe('JOHN D. DELOS SANTOS')
        ->and($first['check_date'])->toBe('2026-07-01');

    $second = collect($result->rows)->firstWhere('serial_no', '000100002');
    expect($second['amount'])->toBe(539000.0)
        ->and($second['gross_taxable_amount'])->toBe(550000.0)
        ->and($second['withholding_tax'])->toBe(11000.0);

    expect($result->meta['report_no'] ?? null)->toBe('2026-07-001')
        ->and($result->meta['fund_cluster'] ?? '')->toContain('101-MOOE');
});

it('parses the LANDBANK bank statement including the balance-forward row', function () {
    $result = (new BankStatementImportParser)->parse(fixture('bank_statement.csv'));

    expect($result->errors)->toBeEmpty()
        ->and($result->rows)->toHaveCount(2);

    $forward = collect($result->rows)->firstWhere('is_balance_forward', true);
    expect($forward)->not->toBeNull()
        ->and($forward['running_balance'])->toBe(1010000.0);

    $txn = collect($result->rows)->firstWhere('is_balance_forward', false);
    expect($txn['check_no'])->toBe('000100002')
        ->and($txn['debit'])->toBe(539000.0)
        ->and($txn['credit'])->toBe(0.0)
        ->and($txn['running_balance'])->toBe(471000.0)
        ->and($txn['description'])->toContain('ICC-LOCAL CHECK');

    expect($result->meta['account_number'] ?? null)->toBe('1292-0001-01');
});
