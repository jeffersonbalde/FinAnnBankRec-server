<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an audit_logs row on create / update / delete of the model.
 * Add `protected array $auditExclude = [...]` to a model to skip noisy fields.
 */
trait RecordsAudit
{
    /** Never written to the audit trail, whatever the model. */
    private const SENSITIVE_FIELDS = ['password', 'remember_token'];

    public static function bootRecordsAudit(): void
    {
        static::created(fn (Model $model) => $model->writeAudit(
            'created',
            collect($model->getAttributes())
                ->except(array_merge(self::SENSITIVE_FIELDS, $model->auditExclude ?? []))
                ->all(),
        ));

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())
                ->except(array_merge(['updated_at'], self::SENSITIVE_FIELDS, $model->auditExclude ?? []))
                ->all();

            if ($changes !== []) {
                $model->writeAudit('updated', $changes);
            }
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', []));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function writeAudit(string $action, array $changes): void
    {
        $user = Auth::user();

        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'description' => class_basename($this).' '.$action,
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }
}
