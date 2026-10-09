<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Database\Factories\CheckIssuanceFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'created_by',
        'updated_by',
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

    /**
     * The filters the Checks Register screen and its Excel export share.
     *
     * @param  Builder<CheckIssuance>  $query
     * @param  array{bank_account_id?: ?int, status?: ?string, search?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     * @return Builder<CheckIssuance>
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when(! empty($filters['bank_account_id']), fn ($q) => $q->where('bank_account_id', $filters['bank_account_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('check_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('check_date', '<=', $filters['date_to']))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(fn ($inner) => $inner->where('serial_no', 'like', $like)
                    ->orWhere('payee', 'like', $like)
                    ->orWhere('dv_no', 'like', $like)
                    ->orWhere('nature_of_payment', 'like', $like));
            });
    }

    /**
     * The Outstanding Checks page and its Excel export share these: checks still
     * outstanding or stale, narrowed by bank account, status and a search that also
     * looks at the bank account.
     *
     * @param  Builder<CheckIssuance>  $query
     * @param  array{bank_account_id?: ?int, status?: ?string, search?: ?string}  $filters
     * @return Builder<CheckIssuance>
     */
    public function scopeOutstandingFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value])
            ->when(! empty($filters['bank_account_id']), fn ($q) => $q->where('bank_account_id', $filters['bank_account_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(function ($inner) use ($like) {
                    $inner->where('serial_no', 'like', $like)
                        ->orWhere('payee', 'like', $like)
                        ->orWhereHas('bankAccount', function ($account) use ($like) {
                            $account->where('account_number', 'like', $like)
                                ->orWhere('bank_short_name', 'like', $like)
                                ->orWhere('bank_name', 'like', $like)
                                ->orWhere('fund_cluster', 'like', $like);
                        });
                });
            });
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
