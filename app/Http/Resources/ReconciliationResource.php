<?php

namespace App\Http\Resources;

use App\Models\Reconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reconciliation
 */
class ReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'period_type' => $this->period_type,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'statement_label' => $this->statement_label,
            'report_no' => $this->report_no,
            'unadjusted_book_balance' => (float) $this->unadjusted_book_balance,
            'unadjusted_bank_balance' => (float) $this->unadjusted_bank_balance,
            'adjusted_book_balance' => (float) $this->adjusted_book_balance,
            'adjusted_bank_balance' => (float) $this->adjusted_bank_balance,
            'difference' => (float) $this->difference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_editable' => $this->status->isEditable(),
            'prepared_by' => $this->whenLoaded('preparedBy', fn () => $this->preparedBy?->name),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'prepared_at' => $this->prepared_at,
            'certified_at' => $this->certified_at,
            'review_remarks' => $this->review_remarks,
            'import_batches' => ImportBatchResource::collection($this->whenLoaded('importBatches')),
            'counts' => $this->when(isset($this->check_issuances_count) || isset($this->bank_transactions_count), fn () => [
                'check_issuances' => $this->check_issuances_count ?? 0,
                'bank_transactions' => $this->bank_transactions_count ?? 0,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
