<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfCreditRequest
//
//  Validates create and update requests for Letter of Credit
//  agreements, including the LC-type rate card and Fully Secured
//  Time Deposit fields. Extracted from LetterOfCreditController::
//  rules() — was previously validated inline in the controller
//  (Rule 3.2 exception), moved here to match the house style used
//  elsewhere. No rule changed — this is a straight move, not a
//  re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LetterOfCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_id'          => ['required', 'exists:banks,id'],
            'name'             => ['required', 'string', 'max:200'],
            'start_date'       => ['required', 'date'],
            'review_date'      => ['required', 'date', 'after:start_date'],
            'currency_id'      => ['required', 'exists:currencies,id'],
            'lc_security_type' => ['required', 'in:unsecured,fully_secured'],
            'facility_limit'   => ['required_if:lc_security_type,unsecured', 'nullable', 'numeric', 'min:0'],

            // ── Fully Secured — pledged Time Deposit ───────────────
            'lc_td_account_id'    => ['required_if:lc_security_type,fully_secured', 'nullable', 'exists:bank_accounts,id'],
            'lc_td_currency_id'   => ['required_if:lc_security_type,fully_secured', 'nullable', 'exists:currencies,id'],
            'lc_td_amount'        => ['required_if:lc_security_type,fully_secured', 'nullable', 'numeric', 'min:0'],
            'lc_td_interest_rate' => ['nullable', 'numeric', 'min:0'],
            'lc_td_lending_pct'   => ['required_if:lc_security_type,fully_secured', 'nullable', 'numeric', 'min:0', 'max:100'],

            // ── LC Types rate card ─────────────────────────────────
            'lc_types'                            => ['required', 'array', 'min:1'],
            'lc_types.*.lc_type'                  => ['required', 'string', 'max:150'],
            'lc_types.*.cash_cover_pct'            => ['required', 'numeric', 'min:0', 'max:100'],
            'lc_types.*.commission_rate_pct'       => ['required', 'numeric', 'min:0', 'max:100'],
            'lc_types.*.min_commission_fee_amount' => ['required', 'numeric', 'min:0'],
            'lc_types.*.issuance_fee_amount'       => ['required', 'numeric', 'min:0'],

            // ── Financing Terms & Conditions (reuses OD-era columns) ─
            'borrowing_rate'        => ['required', 'numeric', 'min:0'],
            'bank_margin_rate'      => ['required', 'numeric', 'min:0'],
            'min_interest_rate'     => ['nullable', 'numeric', 'min:0'],
            'hdb_rate'              => ['nullable', 'numeric', 'min:0'],
            'follows_corridor_rate' => ['boolean'],
        ];
    }
}
