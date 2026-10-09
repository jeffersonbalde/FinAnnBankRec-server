<?php

namespace App\Models;

use App\Enums\ImportType;
use App\Models\Concerns\RecordsAudit;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory, RecordsAudit;

    /** @var list<string> */
    protected array $auditExclude = ['parsed_preview', 'meta', 'error_log', 'row_count', 'imported_count', 'skipped_count'];

    protected $fillable = [
        'reconciliation_id',
        'bank_account_id',
        'type',
        'original_filename',
        'stored_path',
        'uploaded_by',
        'status',
        'row_count',
        'imported_count',
        'skipped_count',
        'parsed_preview',
        'meta',
        'error_log',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'parsed_preview' => 'array',
            'meta' => 'array',
            'error_log' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    /**
     * What the file adds up to, from the rows it was read into: for a Report of Checks
     * Issued the number of checks and their total; for a bank statement the number of
     * transactions, the total debits and credits, the opening and ending balances, and
     * whether opening - debits + credits really gives the ending balance.
     *
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        $rows = collect($this->parsed_preview ?? []);

        if ($this->type === ImportType::Rci) {
            return [
                'count' => $rows->count(),
                'total_amount' => round((float) $rows->sum('amount'), 2),
            ];
        }

        $transactions = $rows->reject(fn (array $row) => $row['is_balance_forward'] ?? false);
        $opening = $rows->first(fn (array $row) => $row['is_balance_forward'] ?? false)['running_balance'] ?? null;
        $ending = $transactions->pluck('running_balance')->filter(fn ($v) => $v !== null)->last() ?? $opening;
        $debits = round((float) $transactions->sum('debit'), 2);
        $credits = round((float) $transactions->sum('credit'), 2);

        return [
            'count' => $transactions->count(),
            'total_debit' => $debits,
            'total_credit' => $credits,
            'opening_balance' => $opening !== null ? (float) $opening : null,
            'ending_balance' => $ending !== null ? (float) $ending : null,
            // Null when the statement has no balances to check against.
            'adds_up' => $opening !== null && $ending !== null
                ? abs(round((float) $opening - $debits + $credits, 2) - round((float) $ending, 2)) < 0.01
                : null,
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<CheckIssuance, $this> */
    public function checkIssuances(): HasMany
    {
        return $this->hasMany(CheckIssuance::class);
    }

    /** @return HasMany<BankTransaction, $this> */
    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }
}
