<?php

namespace App\Models;

use Database\Factories\MatchRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchRun extends Model
{
    /** @use HasFactory<MatchRunFactory> */
    use HasFactory;

    protected $fillable = [
        'reconciliation_id',
        'run_by',
        'matched_count',
        'outstanding_count',
        'flagged_count',
        'flags',
        'parameters',
    ];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'parameters' => 'array',
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }
}
