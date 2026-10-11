<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierPaymentRequest
//
//  Validates SupplierPaymentController::store()/update() — the
//  payable-side mirror of CustomerPaymentRequest. Same
//  withValidator() approach for branch-on-money_type requirements,
//  same reasoning: Laravel's static rule array can't cleanly express
//  "required only when sibling field X equals Y" for this many
//  combinations.
//
//  Only THREE money_type values here (confirmed by Mahmoud) — not
//  four like Customer Collection.
//
//  cheque.due_date / cheque.cheque_number are required for
//  payable_cheque, same fix already proven on the customer side
//  (nullable + required_if together, not required_if alone — an
//  empty string sent for an irrelevant money_type must never trip a
//  bare type check).
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['required', 'integer', 'exists:partners,id'],
            'payment_type'      => ['required', 'in:settlement,down_payment'],
            'payment_date'      => ['required', 'date'],
            'money_type'        => ['required', 'in:cash_payment,outgoing_transfer,payable_cheque'],
            'reference_number'  => ['nullable', 'string', 'max:100'],

            'pay_currency_id'              => ['required', 'integer', 'exists:currencies,id'],
            'bill_currency_id'             => ['nullable', 'integer', 'exists:currencies,id'],
            'bill_to_pay_exchange_rate'    => ['nullable', 'numeric', 'min:0.000001'],
            'exchange_rate'                => ['nullable', 'numeric', 'min:0.000001'],
            'amount'                        => ['required', 'numeric', 'min:0.01'],

            // Outgoing Transfer / Payable Cheque (source fixed at issue time)
            'source_type'                  => ['nullable', 'in:bank_account,credit_facility'],
            'source_bank_account_id'       => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'source_credit_facility_id'    => ['nullable', 'integer', 'exists:credit_facilities,id'],

            // Cash Payment
            'safe_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'receipt_number'   => ['nullable', 'string', 'max:100'],

            // Payable Cheque
            'cheque'                    => ['nullable', 'array'],
            'cheque.cheque_number'       => ['nullable', 'required_if:money_type,payable_cheque', 'string', 'max:100'],
            'cheque.due_date'            => ['nullable', 'required_if:money_type,payable_cheque', 'date'],

            // Down Payment tag
            'down_payment_project_id'          => ['nullable', 'integer', 'exists:projects,id'],
            'down_payment_purchase_order_id'   => ['nullable', 'integer', 'exists:purchase_orders,id'],

            // Settlement (payment_type = settlement)
            'settlements'                              => ['nullable', 'array'],
            'settlements.*.supplier_bill_id'            => ['required', 'integer', 'exists:supplier_bills,id'],
            'settlements.*.settlement_amount'           => ['required', 'numeric', 'min:0.01'],

            'internal_notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'   => 'Supplier',
            'payment_date' => 'Payment Date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $moneyType = $this->input('money_type');

            // Confirmed: a Payable Cheque's source account is fixed at
            // issue time (unlike a Receivable cheque, whose destination
            // is genuinely unknown until later) — so it needs the same
            // source picker as Outgoing Transfer, not a deferred one.
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
