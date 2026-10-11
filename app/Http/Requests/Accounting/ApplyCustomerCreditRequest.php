<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ApplyCustomerCreditRequest
//
//  Audit M-9 — validates "apply this customer credit balance to an
//  invoice". The invoice must belong to the current company (audit
//  H2); the service then checks it is this customer's, still owes the
//  amount, and that the balance has enough left.
//
//  @layer    Request
//  @depends  CustomerCreditBalanceService
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyCustomerCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware carries the permission check
    }

    public function rules(): array
    {
        $companyId = (int) app('currentCompanyId');

        return [
            'customer_invoice_id' => ['required', 'integer', Rule::exists('customer_invoices', 'id')->where('company_id', $companyId)],
            'amount'              => ['required', 'numeric', 'gt:0'],
            'application_date'    => ['nullable', 'date'],
        ];
    }
}
