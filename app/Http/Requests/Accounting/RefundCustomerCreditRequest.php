<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RefundCustomerCreditRequest
//
//  Audit M-9 — validates a cash refund of a customer credit balance.
//  The bank account / safe must belong to the current company (audit
//  H2); the service checks the safe really is a cash safe and the bank
//  account is in the main currency.
//
//  @layer    Request
//  @depends  CustomerRefund
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerRefund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundCustomerCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware carries the permission check
    }

    public function rules(): array
    {
        $companyId = (int) app('currentCompanyId');

        return [
            'amount'          => ['required', 'numeric', 'gt:0'],
            'refund_date'     => ['nullable', 'date'],
            'source_type'     => ['required', Rule::in([CustomerRefund::SOURCE_CASH_SAFE, CustomerRefund::SOURCE_BANK_ACCOUNT])],
            'bank_account_id' => [
                'required_if:source_type,'.CustomerRefund::SOURCE_BANK_ACCOUNT, 'nullable',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'safe_account_id' => [
                'required_if:source_type,'.CustomerRefund::SOURCE_CASH_SAFE, 'nullable',
                Rule::exists('chart_of_accounts', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))),
            ],
            'wht_adjustment'  => ['nullable', 'numeric', 'min:0'],
            'reference'       => ['nullable', 'string', 'max:255'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
