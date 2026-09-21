<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Database\Factories\CheckIssuanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIssuance extends Model
{
    /** @use HasFactory<CheckIssuanceFactory> */
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'reconciliation_id',
        'import_batch_id',
        'check_date',
        'serial_no',
        'dv_no',
        'or_burs_no',
        'responsibility_center_code',
        'payee',
        'uacs_object_code',
        'nature_of_payment',
        'amount',
        'gross_taxable_amount',
        'withholding_tax',
        'report_no',
        'status',
        'cleared_on',
        'cleared_bank_transaction_id',
        'cancelled_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'cleared_on' => 'date',
            'amount' => 'decimal:2',
            'gross_taxable_amount' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'status' => CheckStatus::class,
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }
}
