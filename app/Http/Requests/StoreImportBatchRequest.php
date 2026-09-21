<?php

namespace App\Http\Requests;

use App\Enums\ImportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportBatchRequest extends FormRequest
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
        return [
            'type' => ['required', Rule::enum(ImportType::class)],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:12288'],
        ];
    }
}
