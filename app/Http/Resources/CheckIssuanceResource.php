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
            'payee' => $this->payee,
            'uacs_object_code' => $this->uacs_object_code,
            'nature_of_payment' => $this->nature_of_payment,
            'amount' => (float) $this->amount,
            'gross_taxable_amount' => $this->gross_taxable_amount !== null ? (float) $this->gross_taxable_amount : null,
            'withholding_tax' => $this->withholding_tax !== null ? (float) $this->withholding_tax : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'cleared_on' => $this->cleared_on?->toDateString(),
            'cleared_bank_transaction_id' => $this->cleared_bank_transaction_id,
            'cancelled_reason' => $this->cancelled_reason,
        ];
    }
}
