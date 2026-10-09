<?php

namespace App\Services\Imports;

use App\Enums\CheckStatus;
use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Models\CheckIssuance;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use App\Services\Reconciliation\CheckRegisterService;
use App\Services\Reconciliation\ReconciliationEngine;
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

        $replaced = false;

        DB::transaction(function () use ($batch, $reconciliation, $rows, &$replaced): void {
            // A period has one bank statement: a new one replaces the one already on file, not adds to it.
            if ($batch->type === ImportType::BankStatement) {
                $replaced = $this->discardOtherStatements($batch, $reconciliation) > 0;
            }

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

        // The old statement's matches went with it: match the checks against the new one straight away.
        if ($replaced) {
            app(ReconciliationEngine::class)->run($reconciliation->fresh(), $batch->uploaded_by);
        }

        // New checks change what is outstanding: bring every open reconciliation of the account up to date,
        // as adding a check by hand does.
        if ($batch->type === ImportType::Rci) {
            app(CheckRegisterService::class)->refreshOpenPeriods($reconciliation->bank_account_id, $this->earliestDate($rows));
        }

        return $batch->refresh();
    }

    /**
     * Remove an import. For a committed bank statement this also lets go of the checks it had
     * cleared, so nothing stays "Cleared" against a statement line that no longer exists.
     */
    public function discard(ImportBatch $batch, bool $refresh = true): void
    {
        $releasedStatement = $batch->type === ImportType::BankStatement && $batch->status === 'committed';

        $removedFrom = $batch->type === ImportType::Rci ? $this->earliestDate($batch->parsed_preview ?? []) : null;

        DB::transaction(function () use ($batch, $releasedStatement): void {
            // Take back the checks this file brought in — but only those that are still outstanding and in a period
            // that can change. A check that has cleared or been cancelled stays in the register as part of the record.
            $batch->checkIssuances()
                ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
                ->where(fn ($q) => $q->whereNull('reconciliation_id')->orWhereHas('reconciliation', fn ($r) => $r->whereIn('status', [ReconciliationStatus::Draft->value, ReconciliationStatus::Returned->value])))
                ->delete();

            if ($releasedStatement) {
                $this->releaseClearedChecks($batch);
            }
            $batch->bankTransactions()->delete();

            $this->clearStatementBalance($batch);

            if ($batch->stored_path) {
                Storage::delete($batch->stored_path);
            }

            $batch->delete();
        });

        if ($releasedStatement && $refresh && $batch->reconciliation !== null) {
            app(ReconciliationEngine::class)->refresh($batch->reconciliation->fresh());
        }

        if ($batch->type === ImportType::Rci && $batch->status === 'committed' && $refresh && $batch->bank_account_id !== null) {
            app(CheckRegisterService::class)->refreshOpenPeriods($batch->bank_account_id, $removedFrom);
        }
    }

    /**
     * The earliest check date in the rows, or null when any has none (so every period is refreshed).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function earliestDate(array $rows): ?string
    {
        $dates = array_map(fn (array $row) => $row['check_date'] ?? null, $rows);

        return $dates === [] || in_array(null, $dates, true) ? null : min($dates);
    }

    /**
     * Take back a statement's balance after the statement it came from is gone, using the
     * one now on file (an analyst's own typed figure is never overwritten).
     */
    public function restoreStatementBalance(ImportBatch $current): void
    {
        $reconciliation = $current->reconciliation;

        if ($reconciliation !== null) {
            $this->applyStatementBalance($reconciliation, $this->suggestedBalanceFromRows($current->parsed_preview ?? []));
        }
    }

    /**
     * Discard every other committed bank statement of this period. Returns how many went.
     */
    public function discardOtherStatements(ImportBatch $keep, Reconciliation $reconciliation): int
    {
        $others = ImportBatch::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->where('type', ImportType::BankStatement->value)
            ->where('status', 'committed')
            ->whereKeyNot($keep->id)
            ->get();

        $others->each(fn (ImportBatch $old) => $this->discard($old, refresh: false));

        return $others->count();
    }

    private function releaseClearedChecks(ImportBatch $batch): void
    {
        $transactionIds = $batch->bankTransactions()->pluck('id');

        if ($transactionIds->isEmpty()) {
            return;
        }

        CheckIssuance::query()
            ->whereIn('cleared_bank_transaction_id', $transactionIds)
            ->update([
                'status' => CheckStatus::Outstanding->value,
                'cleared_on' => null,
                'cleared_bank_transaction_id' => null,
            ]);
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

        $account = $reconciliation->bankAccount;
        $register = app(CheckRegisterService::class);

        foreach ($rows as $row) {
            $date = $row['check_date'] ?? null;

            // A check already sitting in a period that is with the reviewer, or certified, is left as it is.
            $existing = $account->checkIssuances()->where('serial_no', $row['serial_no'])->with('reconciliation')->first();
            if ($existing?->reconciliation?->checksLockedReason() !== null) {
                continue;
            }

            // A check belongs to the period its date falls in — wherever the file was uploaded from.
            // Only a check with no date at all falls back to the reconciliation it was uploaded into.
            $period = $register->periodFor($account->id, $date);
            $periodId = $date === null ? $reconciliation->id : ($period?->status->isEditable() ? $period->id : null);

            // A check already on file keeps its status: importing the report again must not turn a cleared,
            // stale or cancelled check back into an outstanding one. Only a new check starts out outstanding.
            $check = $account->checkIssuances()->firstOrNew(['serial_no' => $row['serial_no']]);
            $isNew = ! $check->exists;

            $check->fill(
                [
                    'updated_by' => $batch->uploaded_by,
                    'reconciliation_id' => $periodId,
                    'import_batch_id' => $batch->id,
                    'check_date' => $date,
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
                ],
            );

            if ($isNew) {
                $check->status = CheckStatus::Outstanding;
                $check->created_by = $batch->uploaded_by;
            }

            $check->save();
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
