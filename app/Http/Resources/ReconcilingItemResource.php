<?php

namespace App\Http\Resources;

use App\Models\ReconcilingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReconcilingItem
 */
class ReconcilingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reconciliation_id' => $this->reconciliation_id,
            'side' => $this->side,
            'category' => $this->category->value,
            'label' => $this->category->label(),
            'operation' => $this->operation,
            'amount' => (float) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'explanatory_comment' => $this->explanatory_comment,
            'schedule_no' => $this->schedule_no,
            'is_auto_generated' => $this->is_auto_generated,
        ];
    }
}
