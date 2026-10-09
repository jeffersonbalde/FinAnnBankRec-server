<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Concerns\RecordsAudit;
use App\Services\Reconciliation\CheckRegisterService;
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

    protected static function booted(): void
    {
        // Checks typed into the register before this period existed now belong to it.
        static::created(fn (Reconciliation $reconciliation) => app(CheckRegisterService::class)->adoptUnassigned($reconciliation));
    }

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

    /**
     * Why this period's checks cannot be added to, changed or removed — in plain
     * words for the person who tried — or null while the period is still open.
     */
    public function checksLockedReason(): ?string
    {
        if ($this->status->isEditable()) {
            return null;
        }

        $period = $this->period_start?->format('M j, Y').' – '.$this->period_end?->format('M j, Y');

        return match ($this->status) {
            ReconciliationStatus::Certified => "It belongs to the {$period} reconciliation, which is Certified and locked.",
            default => "It belongs to the {$period} reconciliation, which is {$this->status->label()}. It has to be returned for revision before this check can change.",
        };
    }

    /**
     * The checks that matter to this period (what the Matching tab lists): issued by its end,
     * not cancelled, and not already cleared before it began. A later month's checks are not
     * yet outstanding, and a check the bank cleared earlier belongs to an earlier period.
     *
     * @return HasMany<CheckIssuance, BankAccount>
     */
    public function matchingChecks(): HasMany
    {
        return $this->bankAccount->checkIssuances()
            ->where('status', '!=', CheckStatus::Cancelled->value)
            ->where(fn ($q) => $q->whereNull('check_date')->orWhere('check_date', '<=', $this->period_end))
            ->where(fn ($q) => $q->whereNull('cleared_on')->orWhere('cleared_on', '>=', $this->period_start))
            ->orderBy('check_date')
            ->orderBy('serial_no');
    }

    public function latestMatchRun(): ?MatchRun
    {
        return $this->matchRuns()->latest()->first();
    }
}
