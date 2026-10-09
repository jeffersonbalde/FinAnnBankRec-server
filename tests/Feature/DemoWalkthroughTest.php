<?php

use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use App\Services\Reconciliation\BrsCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The sample files in the project's DEMO folder and the walk-through written for the
 * client demo (DEMO/DEMO-SCRIPT.docx). If someone edits a file or a rule of the system,
 * this test says the script no longer holds.
 */
function demoFile(string $name): UploadedFile
{
    return new UploadedFile(base_path("../DEMO/{$name}"), $name, null, null, true);
}

function demoImport(Reconciliation $reconciliation, ImportType $type, string $file): void
{
    $manager = app(ImportManager::class);
    $manager->commit($manager->parseUpload($reconciliation, $type, demoFile($file), null));
}

function demoPeriod(BankAccount $account, string $start, string $end, string $label): Reconciliation
{
    return Reconciliation::factory()->for($account)->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => $start,
        'period_end' => $end,
        'statement_label' => $label,
        'unadjusted_bank_balance' => 0,
        'unadjusted_book_balance' => 0,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->account = BankAccount::factory()->create(['account_number' => '1292-0001-01', 'fund_cluster' => '101-MOOE']);
})->skip(fn () => ! is_dir(base_path('../DEMO')), 'The DEMO folder is not next to the server.');

it('September closes balanced: 5 cleared, 4 outstanding, nothing flagged', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $september = demoPeriod($this->account, '2026-09-01', '2026-09-30', 'As of September 30, 2026');

    demoImport($september, ImportType::Rci, '1-RCI-September-2026.xlsx');
    demoImport($september, ImportType::BankStatement, '2-BankStatement-September-2026.csv');

    $this->postJson("/api/v1/reconciliations/{$september->id}/match")->assertOk();

    // The statement fills the bank balance; the analyst types the book balance from the ledger.
    expect((float) $september->fresh()->unadjusted_bank_balance)->toBe(3405411.63);
    $september->update(['unadjusted_book_balance' => 3033549.25]);

    $board = $this->getJson("/api/v1/reconciliations/{$september->id}/matches")->assertOk();
    $statuses = collect($board->json('checks'))->countBy('status');

    expect($statuses['cleared'])->toBe(5)
        ->and($statuses['outstanding'])->toBe(4)
        ->and($board->json('flags'))->toBeEmpty();

    $brs = app(BrsCalculator::class)->compute($september->fresh());
    expect($brs['is_balanced'])->toBeTrue()
        ->and($brs['adjusted_bank_balance'])->toBe(3033811.63);
});

it('October shows what carries over, what needs fixing, and balances once it is fixed', function () {
    $analyst = actingAsRole(UserRole::FinancialAnalyst);

    // September first, so its four uncashed checks are on file.
    $september = demoPeriod($this->account, '2026-09-01', '2026-09-30', 'As of September 30, 2026');
    demoImport($september, ImportType::Rci, '1-RCI-September-2026.xlsx');
    demoImport($september, ImportType::BankStatement, '2-BankStatement-September-2026.csv');
    $this->postJson("/api/v1/reconciliations/{$september->id}/match")->assertOk();
    $september->update(['unadjusted_book_balance' => 3033549.25]);

    $october = demoPeriod($this->account, '2026-10-01', '2026-10-31', 'As of October 8, 2026');
    demoImport($october, ImportType::Rci, '3-RCI-October-2026.xlsx');
    demoImport($october, ImportType::BankStatement, '4-BankStatement-October-2026.csv');
    $this->postJson("/api/v1/reconciliations/{$october->id}/match")->assertOk();

    expect((float) $october->fresh()->unadjusted_bank_balance)->toBe(3786861.63);

    // Running October's matching must not touch what September already cleared.
    expect(CheckIssuance::query()->whereBetween('serial_no', ['000200001', '000200005'])->pluck('status')->map->value->unique()->all())->toBe(['cleared']);

    // September's uncashed checks 000200006 and 000200008 are cleared by the October statement.
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200006', 'status' => 'cleared']);
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200008', 'status' => 'cleared']);
    $this->assertDatabaseHas('check_issuances', ['serial_no' => '000200007', 'status' => 'outstanding']);

    // Two things to put right: a wrongly typed amount, and a check that is not in the register.
    $board = $this->getJson("/api/v1/reconciliations/{$october->id}/matches")->assertOk();
    // The line on the Matching tab: "3 cleared · 7 outstanding · 2 flags".
    $count = fn ($board, array $statuses) => collect($board->json('checks'))->whereIn('status', $statuses)->count();
    expect([$count($board, ['cleared']), $count($board, ['outstanding', 'stale']), count($board->json('flags'))])->toBe([3, 7, 2]);
    $flags = collect($board->json('flags'));
    expect($flags->pluck('type')->sort()->values()->all())->toBe(['amount_mismatch', 'unrecorded_check'])
        ->and($flags->firstWhere('type', 'amount_mismatch')['check_no'])->toBe('000200011')
        ->and($flags->firstWhere('type', 'unrecorded_check')['check_no'])->toBe('000200099');

    // 1) correct the typed amount (61,500.00 -> 16,500.00)
    $typo = CheckIssuance::query()->where('serial_no', '000200011')->firstOrFail();
    $this->putJson("/api/v1/check-issuances/{$typo->id}", [
        'check_date' => '2026-10-02',
        'serial_no' => '000200011',
        'payee' => $typo->payee,
        'amount' => 16500,
    ])->assertOk();

    // 2) record the missing check in the register
    $this->postJson('/api/v1/check-register', [
        'bank_account_id' => $this->account->id,
        'check_date' => '2026-10-05',
        'serial_no' => '000200099',
        'payee' => 'ELENA T. RAMOS',
        'amount' => 8800,
        'nature_of_payment' => 'Payment of training allowance (missed in the report)',
    ])->assertCreated();

    // 3) cancel the check issued to the wrong payee
    $wrong = CheckIssuance::query()->where('serial_no', '000200015')->firstOrFail();
    $this->postJson("/api/v1/check-issuances/{$wrong->id}/cancel", ['reason' => 'Wrong payee, a new check will be issued'])->assertOk();

    $this->postJson("/api/v1/reconciliations/{$october->id}/match")->assertOk();
    $october->update(['unadjusted_book_balance' => 3318721.63]);

    $board = $this->getJson("/api/v1/reconciliations/{$october->id}/matches")->assertOk();
    expect([$count($board, ['cleared']), $count($board, ['outstanding', 'stale']), count($board->json('flags'))])->toBe([5, 5, 0]);

    $brs = app(BrsCalculator::class)->compute($october->fresh());
    expect($brs['is_balanced'])->toBeTrue()
        ->and($brs['adjusted_bank_balance'])->toBe(3323111.63)
        ->and($brs['adjusted_book_balance'])->toBe(3323111.63);

    // What is still waiting to be cashed: two from September, three from October (to be cashed in November).
    $outstanding = CheckIssuance::query()->where('status', 'outstanding')->orderBy('serial_no')->pluck('serial_no')->all();
    expect($outstanding)->toBe(['000200007', '000200009', '000200012', '000200013', '000200014']);
});
