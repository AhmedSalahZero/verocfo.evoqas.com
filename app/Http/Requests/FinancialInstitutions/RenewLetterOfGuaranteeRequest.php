<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewLetterOfGuaranteeRequest
//
//  Validates a Facility Renewal for an LG agreement (facility_type =
//  letter_guarantee) — the gap Mahmoud flagged: the LG Facility had
//  no renewal feature at all, unlike the OD family (Clean OD / CP OD
//  / Contract Assignment OD), which already renews via
//  CreditFacility::renew().
//
//  Much smaller field set than RenewCleanOverdraftRequest — an LG
//  agreement carries no borrowing rate, bank margin, HDB rate, admin
//  fee, or settlement days (those are OD-only concepts). Only
//  review_date, facility_limit, AND the LG-type rate card are ever
//  meaningful here.
//
//  lg_types (added — this was the gap Mahmoud flagged: the original
//  renewal only covered the limit/review date, not the actual TERMS
//  AND CONDITIONS a bank renegotiates at renewal — cash cover %,
//  commission %, commission interval, minimum commission fee, and
//  issuance fee, per LG type). Same validation shape as
//  LetterOfGuaranteeRequest's own Section 2 — a renewal must still
//  leave the facility with at least one complete rate-card row, same
//  as create/update.
//
//  IMPORTANT — this is the LG FACILITY (agreement) renewal, NOT the
//  LG ISSUANCE renewal (see RenewLgIssuanceRequest — a separate
//  lifecycle action on an individual issued guarantee, with its own
//  cash cover / commission rate changes and its own transaction
//  date). The two are unrelated forms reachable from different pages.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewLetterOfGuaranteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date'],
            // Only review_date is required — facility_limit left blank
            // simply carries forward from the previous chapter (see
            // CreditFacility::renew()). review_date can never carry
            // forward: the effective date must be after the CURRENT
            // review date, so a renewal must always state its own new one.
            'review_date'    => ['required', 'date', 'after:effective_date'],
            'facility_limit' => ['nullable', 'numeric', 'gt:0'],
            'notes'          => ['nullable', 'string', 'max:1000'],

            // ── Terms & Conditions (rate card) — same shape as
            // LetterOfGuaranteeRequest's Section 2. Required: a
            // renewal always leaves the facility with a complete,
            // usable rate card, not a partially-blanked one.
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
