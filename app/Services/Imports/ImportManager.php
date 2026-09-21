<?php

namespace App\Services\Imports;

use App\Enums\CheckStatus;
use App\Enums\ImportType;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportManager
{
    /**
     * Store the upload, parse it, and record a "parsed" batch ready for preview.
     */
    public function parseUpload(Reconciliation $reconciliation, ImportType $type, UploadedFile $file, ?int $uploadedBy): ImportBatch
    {
        $path = $file->store("imports/{$reconciliation->id}");
        $absolute = Storage::path($path);

        try {
            $parsed = $this->parserFor($type)->parse($absolute);
        } catch (\Throwable $e) {
            return ImportBatch::create([
                'reconciliation_id' => $reconciliation->id,
                'bank_account_id' => $reconciliation->bank_account_id,
                'type' => $type,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'uploaded_by' => $uploadedBy,
                'status' => 'failed',
                'error_log' => [['row' => 0, 'message' => 'Could not read the file: '.$e->getMessage()]],
            ]);
        }

        if ($type === ImportType::BankStatement) {
            $parsed->meta['suggested_unadjusted_bank_balance'] = $this->suggestedBankBalance($parsed);
        }

        return ImportBatch::create([
            'reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $reconciliation->bank_account_id,
            'type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'uploaded_by' => $uploadedBy,
            'status' => 'parsed',
            'row_count' => $parsed->rowCount(),
            'parsed_preview' => $parsed->rows,
            'meta' => $parsed->meta,
            'error_log' => $parsed->errors,
        ]);
    }

    /**
     * Persist the parsed rows into the domain tables.
     */
    public function commit(ImportBatch $batch): ImportBatch
    {
        if ($batch->status === 'failed') {
            throw new RuntimeException('This import failed to parse and cannot be committed.');
        }

        $reconciliation = $batch->reconciliation()->firstOrFail();
        $rows = $batch->parsed_preview ?? [];

        DB::transaction(function () use ($batch, $reconciliation, $rows): void {
            match ($batch->type) {
                ImportType::Rci => $this->commitRci($batch, $reconciliation, $rows),
                ImportType::BankStatement => $this->commitBankStatement($batch, $reconciliation, $rows),
            };

            $batch->update([
                'status' => 'committed',
                'imported_count' => count($rows),
                'skipped_count' => count($batch->error_log ?? []),
                'committed_at' => now(),
            ]);
        });

        return $batch->refresh();
    }

    public function discard(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $batch->checkIssuances()->delete();
            $batch->bankTransactions()->delete();

            $this->clearStatementBalance($batch);

            if ($batch->stored_path) {
                Storage::delete($batch->stored_path);
            }

            $batch->delete();
        });
    }

    /**
     * Removing a committed statement takes back the bank balance it supplied,
     * unless the analyst has since typed a different figure.
     */
    private function clearStatementBalance(ImportBatch $batch): void
    {
        if ($batch->type !== ImportType::BankStatement || $batch->status !== 'committed') {
            return;
        }

        $reconciliation = $batch->reconciliation;
        $supplied = $batch->meta['suggested_unadjusted_bank_balance'] ?? null;

        if ($reconciliation === null || $supplied === null) {
            return;
        }

        if (abs((float) $reconciliation->unadjusted_bank_balance - (float) $supplied) < 0.005) {
            $reconciliation->update(['unadjusted_bank_balance' => 0]);
            app(BrsCalculator::class)->compute($reconciliation);
        }
    }

    private function parserFor(ImportType $type): ImportParser
    {
        return match ($type) {
            ImportType::Rci => new RciImportParser,
            ImportType::BankStatement => new BankStatementImportParser,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function commitRci(ImportBatch $batch, Reconciliation $reconciliation, array $rows): void
    {
        // Re-importing this batch: drop its previous rows first.
        $batch->checkIssuances()->delete();

        foreach ($rows as $row) {
            $reconciliation->bankAccount->checkIssuances()->updateOrCreate(
                ['serial_no' => $row['serial_no']],
                [
                    'reconciliation_id' => $reconciliation->id,
                    'import_batch_id' => $batch->id,
                    'check_date' => $row['check_date'] ?? null,
                    'dv_no' => $row['dv_no'] ?? null,
                    'or_burs_no' => $row['or_burs_no'] ?? null,
                    'responsibility_center_code' => $row['responsibility_center_code'] ?? null,
                    'payee' => $row['payee'],
                    'uacs_object_code' => $row['uacs_object_code'] ?? null,
                    'nature_of_payment' => $row['nature_of_payment'] ?? null,
                    'amount' => $row['amount'],
                    'gross_taxable_amount' => $row['gross_taxable_amount'] ?? null,
                    'withholding_tax' => $row['withholding_tax'] ?? null,
                    'report_no' => $row['report_no'] ?? null,
                    'status' => CheckStatus::Outstanding,
                ],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function commitBankStatement(ImportBatch $batch, Reconciliation $reconciliation, array $rows): void
    {
        $batch->bankTransactions()->delete();

        foreach ($rows as $row) {
            $reconciliation->bankTransactions()->create([
                'bank_account_id' => $reconciliation->bank_account_id,
                'import_batch_id' => $batch->id,
                'txn_date' => $row['txn_date'] ?? null,
                'servicing_branch' => $row['servicing_branch'] ?? null,
                'check_no' => $row['check_no'] ?? null,
                'description' => $row['description'] ?? null,
                'debit' => $row['debit'] ?? 0,
                'credit' => $row['credit'] ?? 0,
                'running_balance' => $row['running_balance'] ?? null,
                'is_balance_forward' => $row['is_balance_forward'] ?? false,
            ]);
        }

        $this->applyStatementBalance($reconciliation, $this->suggestedBalanceFromRows($rows));
    }

    /**
     * Pre-fill the unadjusted bank balance from the statement's ending balance
     * so the analyst doesn't have to retype it, and recompute the BRS. A figure
     * the analyst already entered is never overwritten, and the book balance
     * (from the agency's ledger) is still entered by hand.
     */
    private function applyStatementBalance(Reconciliation $reconciliation, ?float $balance): void
    {
        if ($balance === null || abs((float) $reconciliation->unadjusted_bank_balance) >= 0.005) {
            return;
        }

        $reconciliation->update(['unadjusted_bank_balance' => $balance]);
        app(BrsCalculator::class)->compute($reconciliation);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function suggestedBalanceFromRows(array $rows): ?float
    {
        $ending = null;
        $forward = null;

        foreach ($rows as $row) {
            if (($row['is_balance_forward'] ?? false) === true) {
                $forward = $row['running_balance'] ?? null;

                continue;
            }
            if (($row['running_balance'] ?? null) !== null) {
                $ending = $row['running_balance'];
            }
        }

        $balance = $ending ?? $forward;

        return $balance === null ? null : (float) $balance;
    }

    /**
     * Ending running balance if we have one, otherwise the balance-forward value.
     */
    private function suggestedBankBalance(ParsedImport $parsed): ?float
    {
        return $this->suggestedBalanceFromRows($parsed->rows);
    }
}
