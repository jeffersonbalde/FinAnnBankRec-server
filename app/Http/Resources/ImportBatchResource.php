<?php

namespace App\Http\Resources;

use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportBatch
 */
class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reconciliation_id' => $this->reconciliation_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'row_count' => $this->row_count,
            'imported_count' => $this->imported_count,
            'skipped_count' => $this->skipped_count,
            'valid_count' => count($this->parsed_preview ?? []),
            'error_count' => count($this->error_log ?? []),
            'preview' => $this->when($request->boolean('with_preview'), fn () => $this->parsed_preview),
            'errors' => $this->error_log ?? [],
            'meta' => $this->meta ?? [],
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'committed_at' => $this->committed_at,
            'created_at' => $this->created_at,
        ];
    }
}
