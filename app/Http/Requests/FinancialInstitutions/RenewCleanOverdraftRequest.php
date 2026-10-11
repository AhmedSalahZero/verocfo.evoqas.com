<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewCleanOverdraftRequest
//
//  Validates a Facility Renewal for an existing Clean Overdraft.
//  Deliberately does NOT include account_number, currency_id,
//  coa_account_id, bank_id, or start_date — a renewal never changes
//  the facility's identity, only what terms apply from the effective
//  date forward. Ported from CashVero's RenewCleanOverdraftRequest,
//  adapted for Vero CFO's field set (adds borrowing_rate /
//  bank_margin_rate — confirmed with Mahmoud these ARE part of
//  renewal here, unlike CashVero where they live in a separate
//  mechanism).
//
//  borrowing_rate is intentionally left as a plain nullable rule
//  here — the "locked when follows_corridor_rate" enforcement isn't
//  a validation concern (the value the user submits may be entirely
//  reasonable on its own), it's a business rule about which field
//  wins, enforced in CleanOverdraftService::renewFacility() by
//  discarding the submitted value rather than rejecting the request.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewCleanOverdraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date'     => ['required', 'date'],
            // Only review_date is required — every other field left
            // blank simply carries forward from the previous chapter
            // (see CreditFacility::renew()). review_date can never
            // carry forward: the effective date is required to be
            // after the CURRENT review date, so a renewal must always
            // state its own new one.
            'review_date'        => ['required', 'date', 'after:effective_date'],
            'facility_limit'     => ['nullable', 'numeric', 'gt:0'],
            'borrowing_rate'     => ['nullable', 'numeric', 'gte:0'],
            'bank_margin_rate'   => ['nullable', 'numeric', 'gte:0'],
            'hdb_rate'           => ['nullable', 'numeric', 'gte:0'],
            'admin_fee_rate'     => ['nullable', 'numeric', 'gte:0'],
            'settled_max_days'   => ['nullable', 'integer', 'gte:0'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }
}
