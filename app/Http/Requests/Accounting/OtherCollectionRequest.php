<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherCollectionRequest
//
//  Validates OtherCollectionController::store()/update() — the
//  atomic "record a collection" action (no draft stage, mirrors
//  CustomerPaymentRequest). Field requirements branch on money_type,
//  enforced via withValidator() for the same reason
//  CustomerPaymentRequest does.
//
//  credit_account_id is ALWAYS required, including for money_type =
//  cheque — unlike Customer Collection where the credit side is
//  system-resolved to AR, here it's the one field the user must
//  always supply themselves (see OtherCollectionService header).
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OtherCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['required', 'integer', 'exists:partners,id'],
            'partner_type'      => ['required', 'in:shareholder,employee,sister_company,supplier,other'],
            // per_diem deliberately NOT in this list — you don't "collect"
            // a per diem back. See OtherCollection class header.
            'employee_advance_type' => ['nullable', 'required_if:partner_type,employee', 'in:custody,advance_salary'],
            'collection_date'   => ['required', 'date'],
            'money_type'        => ['required', 'in:bank_deposit,incoming_transfer,cash_in_safe,cheque'],
            'reference_number'  => ['nullable', 'string', 'max:100'],

            'currency_id'    => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate'  => ['nullable', 'numeric', 'min:0.000001'],
            'amount'          => ['required', 'numeric', 'min:0.01'],

            // Bank Deposit / Incoming Transfer
            'destination_type'                => ['nullable', 'in:bank_account,credit_facility'],
            'destination_bank_account_id'     => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'destination_credit_facility_id'  => ['nullable', 'integer', 'exists:credit_facilities,id'],

            // Cash in Safe
            'safe_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'receipt_number'   => ['nullable', 'string', 'max:100'],

            // Credit side — always required (see header)
            'credit_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],

            // Cheque
            'cheque'                            => ['nullable', 'array'],
            'cheque.drawee_bank_id'             => ['nullable', 'required_if:money_type,cheque', 'integer', 'exists:banks,id'],
            'cheque.cheque_number'              => ['nullable', 'required_if:money_type,cheque', 'string', 'max:100'],
            'cheque.due_date'                   => ['nullable', 'required_if:money_type,cheque', 'date'],
            'cheque.receiving_safe_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            // M-31: further post-dated cheques in the same collection
            'cheque.extra'                         => ['nullable', 'array', 'max:60'],
            'cheque.extra.*.cheque_number'         => ['required_with:cheque.extra', 'string', 'max:100'],
            'cheque.extra.*.due_date'              => ['required_with:cheque.extra', 'date'],
            'cheque.extra.*.amount'                => ['required_with:cheque.extra', 'numeric', 'gt:0'],
            'cheque.extra.*.drawee_bank_id'        => ['nullable', 'integer', 'exists:banks,id'],

            'internal_notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'        => 'Partner',
            'partner_type'      => 'Partner Type',
            'employee_advance_type' => 'Advance Type',
            'collection_date'   => 'Collection Date',
            'credit_account_id' => 'Credit Account',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $moneyType = $this->input('money_type');
            $destinationRequiredTypes = ['bank_deposit', 'incoming_transfer'];

            if (in_array($moneyType, $destinationRequiredTypes, true)) {
                if (! $this->input('destination_type')) {
                    $validator->errors()->add('destination_type', 'Select which account or facility received the money.');
                } elseif ($this->input('destination_type') === 'bank_account' && ! $this->input('destination_bank_account_id')) {
                    $validator->errors()->add('destination_bank_account_id', 'Select the receiving bank account.');
                } elseif ($this->input('destination_type') === 'credit_facility' && ! $this->input('destination_credit_facility_id')) {
                    $validator->errors()->add('destination_credit_facility_id', 'Select the receiving Overdraft facility.');
                }
            }

            if ($moneyType === 'cash_in_safe' && ! $this->input('safe_account_id')) {
                $validator->errors()->add('safe_account_id', 'Select which Safe received the cash.');
            }
        });
    }
}
