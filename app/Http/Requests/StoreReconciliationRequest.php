<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesAmounts;
use Illuminate\Foundation\Http\FormRequest;

class StoreReconciliationRequest extends FormRequest
{
    use NormalizesAmounts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAmounts(['unadjusted_book_balance', 'unadjusted_bank_balance']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'period_type' => ['required', 'in:weekly,monthly'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'statement_label' => ['nullable', 'string', 'max:255'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'unadjusted_book_balance' => ['nullable', 'numeric', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
            'unadjusted_bank_balance' => ['nullable', 'numeric', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
        ];
    }
}
