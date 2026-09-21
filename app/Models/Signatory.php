<?php

namespace App\Models;

use App\Enums\SignatoryBlock;
use Database\Factories\SignatoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signatory extends Model
{
    /** @use HasFactory<SignatoryFactory> */
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'block',
        'name',
        'designation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'block' => SignatoryBlock::class,
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
