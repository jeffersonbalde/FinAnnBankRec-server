<?php

use App\Enums\CheckStatus;
use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CheckIssuance;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function statementPeriod(ReconciliationStatus $status = ReconciliationStatus::Draft): Reconciliation
{
    $account = BankAccount::factory()->create(['fund_cluster' => '101-MOOE']);

    return Reconciliation::factory()->for($account)->create([
        'status' => $status,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'unadjusted_bank_balance' => 0,
    ]);
}

function uploadFixture(Reconciliation $reconciliation, ImportType $type, string $file): ImportBatch
{
    $manager = app(ImportManager::class);
    $upload = new UploadedFile(base_path("tests/fixtures/{$file}"), $file, null, null, true);

    return $manager->commit($manager->parseUpload($reconciliation, $type, $upload, null));
}

beforeEach(fn () => Storage::fake('local'));

it('replaces the bank statement of a period instead of piling the new lines on the old', function () {
    $reconciliation = statementPeriod();

    $first = uploadFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');
    $lines = $reconciliation->bankTransactions()->count();
    expect($lines)->toBeGreaterThan(0);

    $second = uploadFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');

    // Same number of lines as one statement — not double.
    expect($reconciliation->bankTransactions()->count())->toBe($lines)
        ->and(ImportBatch::query()->where('reconciliation_id', $reconciliation->id)->where('type', 'bank_statement')->where('status', 'committed')->pluck('id')->all())
        ->toBe([$second->id]);
    $this->assertDatabaseMissing('import_batches', ['id' => $first->id]);
});

it('matches the checks against the new statement straight after replacing', function () {
    $reconciliation = statementPeriod();
    uploadFixture($reconciliation, ImportType::Rci, 'rci.xlsx');
    uploadFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');
    app(ReconciliationEngine::class)->run($reconciliation->fresh());

    $before = CheckIssuance::query()->where('serial_no', '000100002')->firstOrFail();
    expect($before->status)->toBe(CheckStatus::Cleared);

    uploadFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');

    // Cleared again, and pointing at a bank line that really exists (not the removed one).
    $after = $before->fresh();
    expect($after->status)->toBe(CheckStatus::Cleared)
        ->and($after->cleared_bank_transaction_id)->not->toBe($before->cleared_bank_transaction_id);
    $this->assertDatabaseHas('bank_transactions', ['id' => $after->cleared_bank_transaction_id, 'reconciliation_id' => $reconciliation->id]);
});

it('lets go of the checks a statement had cleared when the statement is removed', function () {
    $reconciliation = statementPeriod();
    uploadFixture($reconciliation, ImportType::Rci, 'rci.xlsx');
    $statement = uploadFixture($reconciliation, ImportType::BankStatement, 'bank_statement.csv');
    app(ReconciliationEngine::class)->run($reconciliation->fresh());

    expect(CheckIssuance::query()->where('serial_no', '000100002')->value('status'))->toBe(CheckStatus::Cleared);

    app(ImportManager::class)->discard($statement);

    expect($reconciliation->bankTransactions()->count())->toBe(0);
    $check = CheckIssuance::query()->where('serial_no', '000100002')->firstOrFail();
    expect($check->status)->toBe(CheckStatus::Outstanding)
        ->and($check->cleared_bank_transaction_id)->toBeNull()
        ->and($check->cleared_on)->toBeNull();
});

it('cleans up periods that already carry the same statement more than once, except locked ones', function () {
    $open = statementPeriod();
    $locked = statementPeriod(ReconciliationStatus::Certified);

    $duplicate = function (Reconciliation $reconciliation, int $copies) {
        return collect(range(1, $copies))->map(function () use ($reconciliation) {
            $batch = ImportBatch::factory()->create([
                'reconciliation_id' => $reconciliation->id,
                'bank_account_id' => $reconciliation->bank_account_id,
                'type' => 'bank_statement',
                'original_filename' => 'statement.csv',
                'status' => 'committed',
                'parsed_preview' => [['txn_date' => '2026-07-05', 'check_no' => '1', 'debit' => 10, 'credit' => 0, 'running_balance' => 500.0]],
            ]);
            BankTransaction::factory()->create([
                'bank_account_id' => $reconciliation->bank_account_id,
                'reconciliation_id' => $reconciliation->id,
                'import_batch_id' => $batch->id,
            ]);

            return $batch;
        });
    };

    $openBatches = $duplicate($open, 3);
    $lockedBatches = $duplicate($locked, 2);

    (require database_path('migrations/2026_10_08_195414_dedupe_bank_statement_batches.php'))->up();

    // The open period keeps only its latest statement, with its balance filled in.
    expect($open->bankTransactions()->count())->toBe(1)
        ->and(ImportBatch::query()->where('reconciliation_id', $open->id)->pluck('id')->all())->toBe([$openBatches->last()->id])
        ->and((float) $open->fresh()->unadjusted_bank_balance)->toBe(500.0);

    // The locked one is exactly as it was certified.
    expect($locked->bankTransactions()->count())->toBe(2)
        ->and(ImportBatch::query()->where('reconciliation_id', $locked->id)->count())->toBe($lockedBatches->count());
});
