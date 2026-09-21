<?php

namespace App\Models;

use App\Enums\ReconciliationStatus;
use App\Models\Concerns\RecordsAudit;
use Database\Factories\ReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reconciliation extends Model
{
    /** @use HasFactory<ReconciliationFactory> */
    use HasFactory, RecordsAudit;

    /** @var list<string> */
    protected array $auditExclude = ['adjusted_book_balance', 'adjusted_bank_balance', 'difference'];

    protected $fillable = [
        'bank_account_id',
        'period_type',
        'period_start',
        'period_end',
        'statement_label',
        'report_no',
        'unadjusted_book_balance',
        'unadjusted_bank_balance',
        'adjusted_book_balance',
        'adjusted_bank_balance',
        'difference',
        'status',
        'prepared_by',
        'reviewed_by',
        'prepared_at',
        'certified_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ReconciliationStatus::class,
            'unadjusted_book_balance' => 'decimal:2',
            'unadjusted_bank_balance' => 'decimal:2',
            'adjusted_book_balance' => 'decimal:2',
            'adjusted_bank_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'prepared_at' => 'datetime',
            'certified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ImportBatch, $this> */
    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
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

    /** @return HasMany<ReconcilingItem, $this> */
    public function reconcilingItems(): HasMany
    {
        return $this->hasMany(ReconcilingItem::class);
    }

    /** @return HasMany<MatchRun, $this> */
    public function matchRuns(): HasMany
    {
        return $this->hasMany(MatchRun::class);
    }

    public function latestMatchRun(): ?MatchRun
    {
        return $this->matchRuns()->latest()->first();
    }
}
