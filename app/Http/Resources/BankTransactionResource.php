<?php

namespace App\Http\Resources;

use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankTransaction
 */
class BankTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'txn_date' => $this->txn_date?->toDateString(),
            'servicing_branch' => $this->servicing_branch,
            'check_no' => $this->check_no,
            'description' => $this->description,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'running_balance' => $this->running_balance !== null ? (float) $this->running_balance : null,
            'is_balance_forward' => $this->is_balance_forward,
            'derived_type' => $this->derived_type->value,
            'derived_type_label' => $this->derived_type->label(),
            'match_status' => $this->match_status->value,
            'matched_check_issuance_id' => $this->matched_check_issuance_id,
        ];
    }
}
