<?php

namespace App\Http\Resources;

use App\Enums\ReconciliationStatus;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One footprint, worded for people: what they did, to which record, and the
 * handful of fields that changed. Used by "My Activity" and the Activity Log.
 *
 * @mixin AuditLog
 */
class ActivityResource extends JsonResource
{
    private const MAX_DETAILS = 6;

    /** How each kind of record is named to a person (rather than its class name). */
    private const NOUNS = [
        'Reconciliation' => 'reconciliation',
        'BankAccount' => 'bank account',
        'User' => 'user account',
        'ImportBatch' => 'imported file',
        'ReconcilingItem' => 'adjustment',
        'CheckIssuance' => 'check',
        'Signatory' => 'signatory',
        'ReferenceUacs' => 'UACS code',
        'BankTransaction' => 'bank transaction',
    ];

    /** Field names that carry no meaning to a reader. */
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'password', 'remember_token'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'who' => $this->user_name ?? 'System',
            'action' => $this->action,
            'kind' => $this->kind(),
            'action_label' => $this->actionLabel(),
            'summary' => $this->summary(),
            'entity' => $this->entityLabel(),
            'details' => $this->details(),
            // Kept for the older screens that read these directly.
            'description' => $this->description,
            'changes' => $this->changes,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
            // Set when the person cleared it from their own list; the administrator still has it.
            'cleared_by_user' => $this->hidden_at !== null,
        ];
    }

    /** created | updated | deleted | login | logout | exported | system | other */
    private function kind(): string
    {
        if (str_starts_with($this->action, 'system.')) {
            return 'system';
        }

        return in_array($this->action, ['created', 'updated', 'deleted', 'login', 'logout', 'exported'], true)
            ? $this->action
            : 'other';
    }

    private function actionLabel(): string
    {
        return match ($this->kind()) {
            'created' => 'Added',
            'updated' => 'Changed',
            'deleted' => 'Removed',
            'login' => 'Signed in',
            'logout' => 'Signed out',
            'exported' => 'Downloaded',
            'system' => match ($this->action) {
                'system.activity_cleared' => 'Cleared',
                'system.audit_purged' => 'Deleted',
                default => 'System',
            },
            default => ucfirst(strtolower(Str::headline($this->action))),
        };
    }

    private function entityLabel(): string
    {
        $type = class_basename((string) $this->auditable_type);

        if (in_array(strtolower($type), ['system', ''], true)) {
            return 'System';
        }

        $name = match ($type) {
            'CheckIssuance' => 'Check',
            'ImportBatch' => 'Import',
            'ReconcilingItem' => 'Adjustment',
            default => Str::headline($type),
        };

        return $this->auditable_id ? "{$name} #{$this->auditable_id}" : $name;
    }

    /** A sentence for the row. Hand-written descriptions win; model events are put into words. */
    private function summary(): string
    {
        $description = (string) $this->description;

        if ($description !== '' && ! preg_match('/^[A-Za-z]+ (created|updated|deleted)$/', $description)) {
            return $description;
        }

        $type = class_basename((string) $this->auditable_type);
        $noun = self::NOUNS[$type] ?? strtolower(Str::headline($type));
        $id = $this->auditable_id ? " #{$this->auditable_id}" : '';

        if ($type === 'Reconciliation' && $this->action === 'updated' && isset($this->changes['status'])) {
            $status = ReconciliationStatus::tryFrom((string) $this->changes['status'])?->label() ?? $this->changes['status'];

            return "Moved reconciliation{$id} to {$status}";
        }

        if ($type === 'ImportBatch') {
            if ($this->action === 'created') {
                return 'Uploaded a file to import';
            }
            if ($this->action === 'updated' && ($this->changes['status'] ?? null) === 'committed') {
                return 'Finished importing a file';
            }
        }

        return match ($this->action) {
            'created' => "Added {$noun}{$id}",
            'updated' => "Changed {$noun}{$id}",
            'deleted' => "Removed {$noun}{$id}",
            default => Str::headline($this->action).($noun !== '' ? " {$noun}{$id}" : ''),
        };
    }

    /**
     * The fields an update changed, as "Label: value" — creations list every column, which is noise.
     *
     * @return list<string>
     */
    private function details(): array
    {
        if ($this->action !== 'updated' || ! is_array($this->changes)) {
            return [];
        }

        return collect($this->changes)
            ->except(self::HIDDEN_FIELDS)
            ->filter(fn ($value) => ! is_array($value))
            ->map(fn ($value, $field) => Str::headline((string) $field).': '.Str::limit(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) ($value ?? '—'), 60))
            ->take(self::MAX_DETAILS)
            ->values()
            ->all();
    }
}
