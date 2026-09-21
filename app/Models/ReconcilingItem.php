<?php

namespace App\Models;

use App\Enums\ReconcilingItemCategory;
use Database\Factories\ReconcilingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReconcilingItem extends Model
{
    /** @use HasFactory<ReconcilingItemFactory> */
    use HasFactory;

    protected $fillable = [
        'reconciliation_id',
        'side',
        'category',
        'operation',
        'amount',
        'explanatory_comment',
        'schedule_no',
        'is_auto_generated',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => ReconcilingItemCategory::class,
            'amount' => 'decimal:2',
            'is_auto_generated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function signedAmount(): float
    {
        return $this->operation === 'add' ? (float) $this->amount : -(float) $this->amount;
    }
}
