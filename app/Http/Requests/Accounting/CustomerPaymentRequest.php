<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerPaymentRequest
//
//  Validates CustomerPaymentController::store() — the single, atomic
//  "record a collection" action (design doc: no draft stage). Field
//  requirements branch on money_type and collection_type, enforced
//  via withValidator() rather than static conditional rules, since
//  Laravel's declarative rule array can't easily express "required
//  only when sibling field X equals Y" for this many combinations
//  cleanly.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['required', 'integer', 'exists:partners,id'],
            'collection_type'   => ['required', 'in:settlement,down_payment'],
            'payment_date'      => ['required', 'date'],
            'money_type'        => ['required', 'in:bank_deposit,incoming_transfer,cash_in_safe,cheque'],
            'reference_number'  => ['nullable', 'string', 'max:100'],

            'receive_currency_id'              => ['required', 'integer', 'exists:currencies,id'],
            'invoice_currency_id'               => ['nullable', 'integer', 'exists:currencies,id'],
            'invoice_to_receive_exchange_rate'  => ['nullable', 'numeric', 'min:0.000001'],
            'exchange_rate'                     => ['nullable', 'numeric', 'min:0.000001'],
            'amount'                             => ['required', 'numeric', 'min:0.01'],

            // Bank Deposit / Incoming Transfer
            'destination_type'                  => ['nullable', 'in:bank_account,credit_facility'],
            'destination_bank_account_id'       => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'destination_credit_facility_id'    => ['nullable', 'integer', 'exists:credit_facilities,id'],

            // Cash in Safe
            'safe_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'receipt_number'   => ['nullable', 'string', 'max:100'],

            // Cheque
            'cheque'                              => ['nullable', 'array'],
            'cheque.drawee_bank_id'                => ['nullable', 'required_if:money_type,cheque', 'integer', 'exists:banks,id'],
            'cheque.cheque_number'                 => ['nullable', 'required_if:money_type,cheque', 'string', 'max:100'],
            'cheque.due_date'                      => ['nullable', 'required_if:money_type,cheque', 'date'],
            'cheque.receiving_safe_account_id'     => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            // M-31: further post-dated cheques in the same collection
            'cheque.extra'                         => ['nullable', 'array', 'max:60'],
            'cheque.extra.*.cheque_number'         => ['required_with:cheque.extra', 'string', 'max:100'],
            'cheque.extra.*.due_date'              => ['required_with:cheque.extra', 'date'],
            'cheque.extra.*.amount'                => ['required_with:cheque.extra', 'numeric', 'gt:0'],
            'cheque.extra.*.drawee_bank_id'        => ['nullable', 'integer', 'exists:banks,id'],

            // Down Payment tag
            'down_payment_project_id'      => ['nullable', 'integer', 'exists:projects,id'],
            'down_payment_sales_order_id'  => ['nullable', 'integer', 'exists:sales_orders,id'],

            // Settlement (collection_type = settlement)
            'settlements'                              => ['nullable', 'array'],
            'settlements.*.customer_invoice_id'         => ['required', 'integer', 'exists:customer_invoices,id'],
            'settlements.*.settlement_amount'           => ['required', 'numeric', 'min:0.01'],
            // Optional — a customer withholding tax against this
            // specific invoice on this specific settlement. Zero/blank
            // is the normal case and generates no extra journal line
            // (see CustomerPaymentService::postPaymentTransaction()).
            'settlements.*.wht_amount'                  => ['nullable', 'numeric', 'min:0'],

            'internal_notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'   => 'Customer',
            'payment_date' => 'Receiving Date',
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

            if ($this->input('collection_type') === 'settlement' && empty($this->input('settlements'))
                && $this->input('down_payment_project_id') === null && $this->input('down_payment_sales_order_id') === null) {
                // A Settlement with zero invoices picked and no down-payment
                // tag is allowed — the whole amount just becomes an
                // implicit, untagged down payment (design doc Section 7).
                // No error here; this is a deliberate no-op check kept
                // for future tightening if Mahmoud wants it required.
            }
        });
    }
}