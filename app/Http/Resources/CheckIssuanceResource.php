<?php

namespace App\Http\Resources;

use App\Models\CheckIssuance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CheckIssuance
 */
class CheckIssuanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'check_date' => $this->check_date?->toDateString(),
            'serial_no' => $this->serial_no,
            'dv_no' => $this->dv_no,
            'or_burs_no' => $this->or_burs_no,
            'responsibility_center_code' => $this->responsibility_center_code,
            'payee' => $this->payee,
            'uacs_object_code' => $this->uacs_object_code,
            'nature_of_payment' => $this->nature_of_payment,
            'amount' => (float) $this->amount,
            'gross_taxable_amount' => $this->gross_taxable_amount !== null ? (float) $this->gross_taxable_amount : null,
            'withholding_tax' => $this->withholding_tax !== null ? (float) $this->withholding_tax : null,
            'report_no' => $this->report_no,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'cleared_on' => $this->cleared_on?->toDateString(),
            'cleared_bank_transaction_id' => $this->cleared_bank_transaction_id,
            'cancelled_reason' => $this->cancelled_reason,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => [
                'id' => $this->bankAccount->id,
                'name' => ($this->bankAccount->bank_short_name ?: $this->bankAccount->bank_name).' · '.$this->bankAccount->account_number,
                'fund_cluster' => $this->bankAccount->fund_cluster,
            ]),
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_by_name' => $this->whenLoaded('updater', fn () => $this->updater?->name),
            'created_at' => $this->created_at?->toDateTimeString(),
            'reconciliation_id' => $this->reconciliation_id,
            // No import batch behind it — typed in by hand, so it can be edited/removed directly.
            'is_manual' => $this->import_batch_id === null,
            // Set only when the check's own reconciliation is eager-loaded (e.g. the
            // matching board) — lets the UI badge a check carried over from an
            // earlier period instead of one recorded this period.
            // Also only when the reconciliation is loaded: whether its period is locked
            // (For Review / Certified), and why — so the UI offers only what will work.
            'period_locked' => $this->when($this->relationLoaded('reconciliation'), fn () => $this->reconciliation?->checksLockedReason() !== null),
            'lock_reason' => $this->when($this->relationLoaded('reconciliation'), fn () => $this->reconciliation?->checksLockedReason()),
            'origin_period_label' => $this->whenLoaded(
                'reconciliation',
                fn () => $this->reconciliation?->period_end?->format('M Y'),
            ),
        ];
    }
}
