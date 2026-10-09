<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesAmounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckIssuanceRequest extends FormRequest
{
    use NormalizesAmounts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAmounts(['amount', 'gross_taxable_amount', 'withholding_tax']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $checkIssuance = $this->route('checkIssuance');
        // A new check names its bank account; an existing one keeps the account it has.
        $bankAccountId = $checkIssuance?->bank_account_id ?? $this->integer('bank_account_id');

        return [
            'bank_account_id' => [$checkIssuance ? 'prohibited' : 'required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            // The date decides which reconciliation period the check belongs to.
            'check_date' => ['required', 'date'],
            'serial_no' => [
                'required', 'string', 'max:100',
                Rule::unique('check_issuances', 'serial_no')
                    ->where(fn ($q) => $q->where('bank_account_id', $bankAccountId))
                    ->ignore($checkIssuance?->id),
            ],
            'dv_no' => ['nullable', 'string', 'max:100'],
            'or_burs_no' => ['nullable', 'string', 'max:100'],
            'responsibility_center_code' => ['nullable', 'string', 'max:100'],
            'payee' => ['required', 'string', 'max:255'],
            'uacs_object_code' => ['nullable', 'string', 'max:50'],
            'nature_of_payment' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'gross_taxable_amount' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'withholding_tax' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'report_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
