<?php

namespace App\Http\Requests\FinancialInstitutions;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleLcIssuanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'settlement_date'              => ['required', 'date'],
            'principal_amount'             => ['required', 'numeric', 'min:0'],
            'interest_amount'              => ['nullable', 'numeric', 'min:0'],
            'interest_expense_account_id'  => [
                Rule::requiredIf(fn () => Money::isPositive($this->input('interest_amount', 0))),
                'nullable', 'exists:chart_of_accounts,id',
            ],
            'settlement_from_account_id'   => ['required', 'exists:bank_accounts,id'],
            'notes'                        => ['nullable', 'string'],
        ];
    }
}
