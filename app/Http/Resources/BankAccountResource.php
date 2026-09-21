<?php

namespace App\Http\Resources;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 */
class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'bank_short_name' => $this->bank_short_name,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'entity_name' => $this->entity_name,
            'fund_cluster' => $this->fund_cluster,
            'is_active' => $this->is_active,
            'signatories' => SignatoryResource::collection($this->whenLoaded('signatories')),
            'signatories_count' => $this->whenCounted('signatories'),
            'created_at' => $this->created_at,
        ];
    }
}
