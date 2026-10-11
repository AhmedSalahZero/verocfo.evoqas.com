<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherPaymentRequest
//
//  Validates OtherPaymentController::store()/update() — payable-side
//  mirror of OtherCollectionRequest. Only THREE money_type values
//  (cash_payment / outgoing_transfer / payable_cheque), same as
//  Supplier Payment — not four like Customer/Other Collection.
//
//  debit_account_id is ALWAYS required, including for money_type =
//  payable_cheque — the one field the user must always supply
//  themselves, since no AP exists for these partners.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OtherPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['required', 'integer', 'exists:partners,id'],
            'partner_type'      => ['required', 'in:shareholder,employee,sister_company,customer,other'],
            'employee_advance_type' => ['nullable', 'required_if:partner_type,employee', 'in:custody,advance_salary,per_diem,reimbursement'],
            'payment_date'      => ['required', 'date'],
            'money_type'        => ['required', 'in:cash_payment,outgoing_transfer,payable_cheque'],
            'reference_number'  => ['nullable', 'string', 'max:100'],

            'currency_id'    => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate'  => ['nullable', 'numeric', 'min:0.000001'],
            'amount'          => ['required', 'numeric', 'min:0.01'],

            // Outgoing Transfer / Payable Cheque (source fixed at issue time)
            'source_type'               => ['nullable', 'in:bank_account,credit_facility'],
            'source_bank_account_id'    => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'source_credit_facility_id' => ['nullable', 'integer', 'exists:credit_facilities,id'],

            // Cash Payment
            'safe_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'receipt_number'  => ['nullable', 'string', 'max:100'],

            // Debit side — always required (see header)
            'debit_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],

            // Payable Cheque
            'cheque'                => ['nullable', 'array'],
            'cheque.cheque_number'  => ['nullable', 'required_if:money_type,payable_cheque', 'string', 'max:100'],
            'cheque.due_date'       => ['nullable', 'required_if:money_type,payable_cheque', 'date'],

            'internal_notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'        => 'Partner',
            'partner_type'      => 'Partner Type',
            'employee_advance_type' => 'Advance Type',
            'payment_date'      => 'Payment Date',
            'debit_account_id'  => 'Debit Account',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $moneyType = $this->input('money_type');

            // A Payable Cheque's source account is fixed at issue time —
            // same reasoning/requirement as Supplier Payment.
            if (in_array($moneyType, ['outgoing_transfer', 'payable_cheque'], true)) {
                if (! $this->input('source_type')) {
                    $validator->errors()->add('source_type', 'Select which account or facility the money is paid from.');
                } elseif ($this->input('source_type') === 'bank_account' && ! $this->input('source_bank_account_id')) {
                    $validator->errors()->add('source_bank_account_id', 'Select the paying bank account.');
                } elseif ($this->input('source_type') === 'credit_facility' && ! $this->input('source_credit_facility_id')) {
                    $validator->errors()->add('source_credit_facility_id', 'Select the paying Overdraft facility.');
                }
            }

            if ($moneyType === 'cash_payment' && ! $this->input('safe_account_id')) {
                $validator->errors()->add('safe_account_id', 'Select which Safe the cash was paid from.');
            }
        });
    }
}
