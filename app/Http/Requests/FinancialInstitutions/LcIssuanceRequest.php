<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcIssuanceRequest
//
//  Validates create and update requests for individual LC Issuances
//  (both types — Against LC Facility, 100% Cash Cover — share one
//  form and one rule set, same as LgIssuanceRequest).
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LcIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issuance_type'    => ['required', 'in:facility,cash_cover'],
            'posting_mode'     => ['required', 'in:new_issuance,opening_issuance'],
            'credit_facility_id'  => ['nullable', 'exists:credit_facilities,id'],
            'facility_lc_type_id' => ['nullable', 'exists:facility_lc_types,id'],
            'lc_type'          => ['required', 'string', 'max:150'],
            'transaction_name' => ['required', 'string', 'max:200'],
            'bank_id'          => ['required', 'exists:banks,id'],
            'currency_id'      => ['required', 'exists:currencies,id'],
            'lc_code'          => ['nullable', 'string', 'max:100'],
            'supplier_partner_id'  => ['required', 'exists:partners,id'],
            'purchase_order_id'    => ['nullable', 'exists:purchase_orders,id'],
            'issuance_date'    => ['required', 'date'],
            'duration_days'    => ['required', 'integer', 'min:1'],
            'lc_amount'        => ['required', 'numeric', 'min:0.01'],
            'exchange_rate'    => ['required', 'numeric', 'min:0.000001'],
            'cash_cover_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cash_cover_currency_id'      => ['nullable', 'exists:currencies,id'],
            'commission_rate_pct'       => ['required', 'numeric', 'min:0'],
            'min_commission_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'issuance_fee_amount'       => ['nullable', 'numeric', 'min:0'],
            'cash_cover_account_id'      => ['nullable', 'exists:bank_accounts,id'],
            'fees_commission_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'financed_by'      => ['required', 'in:bank,self'],
            'financing_duration_days' => ['nullable', 'integer', 'min:0'],
            'comment'          => ['nullable', 'string'],
        ];
    }
}
