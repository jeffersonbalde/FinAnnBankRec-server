<?php

namespace App\Http\Requests;

use App\Enums\ReconcilingItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconcilingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $manual = array_map(fn (ReconcilingItemCategory $c) => $c->value, ReconcilingItemCategory::manualCases());

        return [
            'category' => ['required', Rule::in($manual)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'explanatory_comment' => ['required', 'string', 'max:1000'],
            'schedule_no' => ['nullable', 'string', 'max:50'],
        ];
    }
}
