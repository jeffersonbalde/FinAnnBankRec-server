<?php

namespace App\Http\Requests;

use App\Enums\SignatoryBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSignatoryRequest extends FormRequest
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
            'block' => ['required', Rule::enum(SignatoryBlock::class)],
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'sort_order' => ['integer', 'min:0', 'max:255'],
        ];
    }
}
