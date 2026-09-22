<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\RecordsAudit;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, RecordsAudit;

    /** @var list<string> */
    protected array $auditExclude = ['password', 'remember_token', 'email_verified_at'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'designation',
        'avatar_path',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'avatar_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, strict: true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Whether this account has left any trace in the system (prepared/reviewed a
     * reconciliation, uploaded an import, run matching, or has any audit entry).
     * A user with activity can only be deactivated, never permanently deleted,
     * so that history keeps its accountable name.
     */
    public function hasActivityRecords(): bool
    {
        return Reconciliation::query()
            ->where('prepared_by', $this->id)
            ->orWhere('reviewed_by', $this->id)
            ->exists()
            || ImportBatch::query()->where('uploaded_by', $this->id)->exists()
            || MatchRun::query()->where('run_by', $this->id)->exists()
            || AuditLog::query()->where('user_id', $this->id)->exists();
    }
}
