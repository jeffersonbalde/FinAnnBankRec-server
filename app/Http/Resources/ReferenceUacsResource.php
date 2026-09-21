<?php

namespace App\Http\Resources;

use App\Models\ReferenceUacs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReferenceUacs
 */
class ReferenceUacsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
