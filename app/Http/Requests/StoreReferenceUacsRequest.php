<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReferenceUacsRequest extends FormRequest
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
        $id = $this->route('referenceUac')?->id;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('reference_uacs', 'code')->ignore($id),
            ],
            'description' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
