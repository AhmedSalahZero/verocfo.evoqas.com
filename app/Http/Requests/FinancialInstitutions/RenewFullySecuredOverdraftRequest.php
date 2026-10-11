<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewFullySecuredOverdraftRequest
//
//  Validates a Facility Renewal for an existing Fully Secured
//  Overdraft. Mirrors RenewCleanOverdraftRequest — deliberately does
//  NOT include account_number, currency_id, coa_account_id, bank_id,
//  or start_date, since a renewal never changes the facility's
//  identity, only what terms apply from the effective date forward.
//
//  What's different from Clean Overdraft's renewal: no facility_limit
//  field here either — same reasoning as FullySecuredOverdraftRequest.
//  A renewal instead can update fsod_td_amount and/or
//  fsod_td_lending_pct (e.g. the TD grew, or the bank changed the
//  haircut), and CreditFacility::renew() recomputes facility_limit
//  from whichever pairing is live in the new chapter (see that
//  method's own comment for why it's a recompute, not a carry-
//  forward of the raw number).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewFullySecuredOverdraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date'       => ['required', 'date'],
            // Only review_date is required — every other field left
            // blank simply carries forward from the previous chapter
            // (see CreditFacility::renew()).
            'review_date'          => ['required', 'date', 'after:effective_date'],
            'fsod_td_amount'       => ['nullable', 'numeric', 'gt:0'],
            'fsod_td_lending_pct'  => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'borrowing_rate'       => ['nullable', 'numeric', 'gte:0'],
            'bank_margin_rate'     => ['nullable', 'numeric', 'gte:0'],
            'hdb_rate'             => ['nullable', 'numeric', 'gte:0'],
            'admin_fee_rate'       => ['nullable', 'numeric', 'gte:0'],
            'settled_max_days'     => ['nullable', 'integer', 'gte:0'],
            'notes'                => ['nullable', 'string', 'max:1000'],
        ];
    }
}
