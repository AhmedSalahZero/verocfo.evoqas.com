<?php

namespace App\Http\Requests\FinancialInstitutions;

use Illuminate\Foundation\Http\FormRequest;

class MarkLcIssuanceAsPaidRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'paid_at'                  => ['required', 'date'],
            'supplier_bill_id'         => ['required', 'exists:supplier_bills,id'],
            'amount_paid_to_supplier'  => ['nullable', 'numeric', 'min:0.01'],
            'payment_account_id'       => ['nullable', 'exists:bank_accounts,id'],
            'notes'                    => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $issuance = $this->route('letterOfCreditIssuance') ?? $this->route('issuance');
            if ($issuance && $issuance->financed_by === 'self' && ! $this->input('payment_account_id')) {
                $validator->errors()->add('payment_account_id', 'A payment account is required for a self-financed LC.');
            }
        });
    }
}
