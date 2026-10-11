<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewLetterOfCreditRequest
//
//  Validates a Facility Renewal for an LC agreement (facility_type =
//  import_lc) — the gap Mahmoud flagged: LC had no renewal feature
//  at all. Two mockups confirmed the exact shape needed, branching on
//  lc_security_type (read from the route-bound facility, same
//  pattern already used elsewhere for facility-dependent rules):
//
//    - unsecured      → New Limit (typed directly)
//    - fully_secured  → New CD/TD Lending Percentage (Limit is
//                        recalculated automatically from the linked
//                        CD/TD amount × this percentage — the bank
//                        can also grow/shrink the pledged TD amount
//                        itself at renewal, so lc_td_amount is
//                        included too, not just the percentage)
//
//  Shared "Financing Terms & Conditions" fields for both variants:
//  borrowing rate, bank margin, min interest rate, HDB rate, admin
//  fee rate — all optional (blank = unchanged from the current
//  chapter, per CreditFacility::renew()). New Contract End Date maps
//  to review_date (LC's own name for the same field Clean OD calls
//  "review date") and can never carry forward — a renewal must always
//  state its own new one.
//
//  lc_types (the "NEW TERM & CONDITIONS — BY LC TYPE" matrix) is
//  required, same shape as LetterOfCreditRequest's own rate-card
//  rules — a renewal always leaves the facility with a complete,
//  usable rate card, never a partially-blanked one. No
//  commission_interval column here — confirmed absent from LC's rate
//  card, same as the create/update form.
//
//  IMPORTANT — this is the LC FACILITY (agreement) renewal, not an
//  LC issuance renewal (LC issuance's own lifecycle actions, if any,
//  are a separate future concern — see LetterOfCreditIssuanceService).
//
//  @layer    Request
//  @depends  CreditFacility
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewLetterOfCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $facility     = $this->route('facility');
        $fullySecured = $facility?->lc_security_type === 'fully_secured';

        return [
            'effective_date' => ['required', 'date'],
            // Only review_date is required — every other field left
            // blank simply carries forward from the previous chapter
            // (see CreditFacility::renew()).
            'review_date'    => ['required', 'date', 'after:effective_date'],

            // ── Limit — exactly one of these two branches applies ──
            'facility_limit'    => [Rule::prohibitedIf($fullySecured), 'nullable', 'numeric', 'gt:0'],
            'lc_td_amount'      => [Rule::prohibitedIf(!$fullySecured), 'nullable', 'numeric', 'gt:0'],
            'lc_td_lending_pct' => [Rule::prohibitedIf(!$fullySecured), 'nullable', 'numeric', 'gt:0', 'max:100'],

            // ── Financing Terms & Conditions ───────────────────────
            'borrowing_rate'    => ['nullable', 'numeric', 'gte:0'],
            'bank_margin_rate'  => ['nullable', 'numeric', 'gte:0'],
            'min_interest_rate' => ['nullable', 'numeric', 'gte:0'],
            'hdb_rate'          => ['nullable', 'numeric', 'gte:0'],
            'admin_fee_rate'    => ['nullable', 'numeric', 'gte:0'],
            'notes'             => ['nullable', 'string', 'max:1000'],

            // ── Terms & Conditions — by LC Type (rate card) ────────
            'lc_types'                              => ['required', 'array', 'min:1'],
            'lc_types.*.lc_type'                    => ['required', 'string', 'max:150'],
            'lc_types.*.cash_cover_pct'              => ['required', 'numeric', 'min:0', 'max:100'],
            'lc_types.*.commission_rate_pct'         => ['required', 'numeric', 'min:0', 'max:100'],
            'lc_types.*.min_commission_fee_amount'   => ['required', 'numeric', 'min:0'],
            'lc_types.*.issuance_fee_amount'         => ['required', 'numeric', 'min:0'],
        ];
    }
}
