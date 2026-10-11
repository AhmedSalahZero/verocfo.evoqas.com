<?php

namespace App\Http\Requests\FinancialInstitutions;

use Illuminate\Foundation\Http\FormRequest;

class LcIssuanceExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'expense_name'          => ['required', 'string', 'max:200'],
            'expense_date'          => ['required', 'date'],
            'amount'                => ['required', 'numeric', 'min:0.01'],
            'currency_id'           => ['required', 'exists:currencies,id'],
            'exchange_rate'         => ['nullable', 'numeric', 'min:0.000001'],
            'expense_account_id'    => ['required', 'exists:chart_of_accounts,id'],
            'paid_from_account_id'  => ['required', 'exists:bank_accounts,id'],
        ];
    }
}
