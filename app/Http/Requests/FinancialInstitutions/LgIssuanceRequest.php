<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuanceRequest
//
//  Validates create and update requests for individual LG Issuances
//  (all 3 issuance types — Against LG Facility, 100% Cash Cover,
//  Against Time Deposit — share one form and one rule set, matching
//  LgIssuanceService's unified handling). Extracted from
//  LgIssuanceController::rules() — was previously validated inline
//  in the controller (Rule 3.2 exception), moved here to match the
//  house style used elsewhere. No rule changed — this is a straight
//  move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LgIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issuance_type'    => ['required', 'in:facility,cash_cover,time_deposit'],
            'posting_mode'     => ['required', 'in:new_issuance,opening_issuance'],
            'credit_facility_id'  => ['nullable', 'exists:credit_facilities,id'],
            'facility_lg_type_id' => ['nullable', 'exists:facility_lg_types,id'],
            'lg_type'          => ['required', 'string', 'max:150'],
            'transaction_name' => ['required', 'string', 'max:200'],
            'bank_id'          => ['required', 'exists:banks,id'],
            'currency_id'      => ['required', 'exists:currencies,id'],
            'lg_code'          => ['nullable', 'string', 'max:100'],
            'beneficiary_partner_id'        => ['required', 'exists:partners,id'],
            'contract_reference_project_id' => ['nullable', 'exists:projects,id'],
            'purchase_order_so_id'          => ['nullable', 'exists:sales_orders,id'],
            'purchase_order_date'           => ['nullable', 'date'],
            'issuance_date'    => ['required', 'date'],
            'end_date'         => ['required', 'date', 'after:issuance_date'],
            'lg_amount'        => ['required', 'numeric', 'min:0.01'],
            'cash_cover_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cash_cover_currency_id'      => ['nullable', 'exists:currencies,id'],
            'commission_fees_currency_id' => ['nullable', 'exists:currencies,id'],
            'commission_rate_pct'       => ['required', 'numeric', 'min:0'],
            'min_commission_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'issuance_fee_amount'       => ['nullable', 'numeric', 'min:0'],
            'commission_interval'       => ['required', 'in:monthly,quarterly,semi_annual,annual'],
            'cash_cover_account_id'      => ['nullable', 'exists:bank_accounts,id'],
            'fees_commission_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'time_deposit_account_id'    => ['nullable', 'exists:bank_accounts,id'],
            'comment'          => ['nullable', 'string'],
        ];
    }
}
