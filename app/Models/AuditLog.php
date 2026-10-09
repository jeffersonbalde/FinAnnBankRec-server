<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Sign-ins and sign-outs are footprints, but not "work": they never block deleting an account. */
    public const SESSION_ACTIONS = ['login', 'logout'];

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'auditable_type',
        'auditable_id',
        'description',
        'changes',
        'ip_address',
        'hidden_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * Write a footprint for something a person did (sign in, export, link a check…).
     * Model create/update/delete are written automatically by RecordsAudit.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function record(?User $user, string $action, string $description, ?Model $subject = null, array $changes = []): self
    {
        return static::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass() ?? ($user ? $user->getMorphClass() : 'system'),
            'auditable_id' => $subject?->getKey() ?? $user?->id ?? 0,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * What the Activity screens filter on, shared by "My Activity" and the
     * administrator's Activity Log.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array{search?: ?string, action?: ?string, type?: ?string, user_id?: ?int, date_from?: ?string, date_to?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when(! empty($filters['user_id']), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(! empty($filters['action']), fn ($q) => $q->where('action', $filters['action']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('auditable_type', 'like', '%'.$filters['type'].'%'))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(fn ($inner) => $inner->where('description', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('auditable_type', 'like', $like)
                    ->orWhere('action', 'like', $like));
            });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
