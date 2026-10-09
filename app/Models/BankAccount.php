<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory, RecordsAudit;

    protected $fillable = [
        'bank_name',
        'bank_short_name',
        'account_number',
        'account_name',
        'entity_name',
        'fund_cluster',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Signatory, $this>
     */
    public function signatories(): HasMany
    {
        return $this->hasMany(Signatory::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Reconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    /**
     * @return HasMany<CheckIssuance, $this>
     */
    public function checkIssuances(): HasMany
    {
        return $this->hasMany(CheckIssuance::class);
    }

    /**
     * Whether any reconciliation work hangs off this account. Deleting it would
     * wipe all of that (the foreign keys cascade), so the account is only
     * deleted while it is still empty — otherwise it is deactivated instead.
     */
    public function hasRecords(): bool
    {
        return $this->reconciliations()->exists()
            || $this->checkIssuances()->exists()
            || BankTransaction::query()->where('bank_account_id', $this->id)->exists();
    }
}
