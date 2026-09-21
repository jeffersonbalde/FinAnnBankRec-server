<?php

namespace App\Models;

use App\Enums\BankTransactionType;
use App\Enums\MatchStatus;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'reconciliation_id',
        'import_batch_id',
        'txn_date',
        'servicing_branch',
        'check_no',
        'description',
        'debit',
        'credit',
        'running_balance',
        'is_balance_forward',
        'derived_type',
        'matched_check_issuance_id',
        'match_status',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'datetime',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'is_balance_forward' => 'boolean',
            'derived_type' => BankTransactionType::class,
            'match_status' => MatchStatus::class,
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    /** @return BelongsTo<CheckIssuance, $this> */
    public function matchedCheckIssuance(): BelongsTo
    {
        return $this->belongsTo(CheckIssuance::class, 'matched_check_issuance_id');
    }
}
