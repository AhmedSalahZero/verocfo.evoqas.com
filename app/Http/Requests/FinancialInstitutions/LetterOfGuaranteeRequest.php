<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfGuaranteeRequest
//
//  Validates create and update requests for Letter of Guarantee
//  agreements, including the LG-type rate card. Extracted from
//  LetterOfGuaranteeController::rules() — was previously validated
//  inline in the controller (Rule 3.2 exception), moved here to
//  match the house style used elsewhere. No rule changed — this is
//  a straight move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LetterOfGuaranteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Section 1 — Contract Main Information ─────────────
            'bank_id'        => ['required', 'exists:banks,id'],
            'name'           => ['required', 'string', 'max:200'],
            'start_date'     => ['required', 'date'],
            'review_date'    => ['required', 'date', 'after:start_date'],
            'currency_id'    => ['required', 'exists:currencies,id'],
            'facility_limit' => ['required', 'numeric', 'min:0'],

            // ── Section 2 — Terms & Conditions (rate card) ─────────
            // At least one LG type — without one there are no terms
            // to apply when a guarantee is eventually issued.
            'lg_types'                              => ['required', 'array', 'min:1'],
            'lg_types.*.lg_type'                    => ['required', 'string', 'max:150'],
            'lg_types.*.cash_cover_pct'              => ['required', 'numeric', 'min:0', 'max:100'],
            'lg_types.*.commission_rate_pct'         => ['required', 'numeric', 'min:0', 'max:100'],
            'lg_types.*.commission_interval'         => ['required', 'in:monthly,quarterly,semi_annual,annual'],
            'lg_types.*.min_commission_fee_amount'   => ['required', 'numeric', 'min:0'],
            'lg_types.*.issuance_fee_amount'         => ['required', 'numeric', 'min:0'],
        ];
    }
}
