<?php

namespace App\Http\Resources;

use App\Models\Signatory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Signatory
 */
class SignatoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'block' => $this->block->value,
            'block_label' => $this->block->label(),
            'name' => $this->name,
            'designation' => $this->designation,
            'sort_order' => $this->sort_order,
        ];
    }
}
