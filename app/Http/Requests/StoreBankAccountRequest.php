<?php

namespace App\Http\Requests;

use App\Enums\SignatoryBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBankAccountRequest extends FormRequest
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
        $bankAccountId = $this->route('bank_account')?->id;

        $rules = [
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_short_name' => ['required', 'string', 'max:50'],
            'account_number' => [
                'required', 'string', 'max:255',
                Rule::unique('bank_accounts', 'account_number')->ignore($bankAccountId),
            ],
            'account_name' => ['required', 'string', 'max:255'],
            'entity_name' => ['required', 'string', 'max:255'],
            'fund_cluster' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];

        // Signatories are created with the account on POST only.
        if ($this->isMethod('POST')) {
            $rules['signatories'] = ['required', 'array', 'size:3'];
            $rules['signatories.*.block'] = ['required', Rule::enum(SignatoryBlock::class)];
            $rules['signatories.*.name'] = ['required', 'string', 'max:255'];
            $rules['signatories.*.designation'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        if (! $this->isMethod('POST')) {
            return [];
        }

        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('signatories')) {
                    return;
                }

                $blocks = collect($this->input('signatories', []))->pluck('block')->filter()->values();
                $expected = collect(SignatoryBlock::values());

                if ($blocks->unique()->count() !== 3 || $blocks->sort()->values()->all() !== $expected->sort()->values()->all()) {
                    $validator->errors()->add(
                        'signatories',
                        'Provide exactly one signatory for Prepared by, Certified Correct, and Disbursing Officer.',
                    );
                }
            },
        ];
    }
}
