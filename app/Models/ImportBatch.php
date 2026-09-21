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
