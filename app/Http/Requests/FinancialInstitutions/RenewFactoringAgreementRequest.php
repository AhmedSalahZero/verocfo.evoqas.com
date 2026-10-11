<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewFactoringAgreementRequest
//
//  Validates a renewal for an existing Factoring Agreement. Mirrors
//  RenewCleanOverdraftRequest — deliberately does NOT include
//  factoring_company_id, coa_account_id, eligible_partner_id, or
//  start_date: a renewal never changes the agreement's identity,
//  only what terms apply from the effective date forward.
//
//  end_date plays the role ODA's review_date plays — the only field
//  that is required and can never carry forward.
//
//  borrowing_rate is left as a plain nullable rule here — the
//  "locked when follows_corridor_rate" enforcement isn't a
//  validation concern, it's a business rule about which value wins,
//  enforced in FactoringService::renewAgreement() by discarding the
//  submitted value rather than rejecting the request.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewFactoringAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date'         => ['required', 'date'],
            'end_date'               => ['required', 'date', 'after:effective_date'],
            'borrowing_rate'         => ['nullable', 'numeric', 'gte:0', 'max:100'],
            'discount_margin_rate'   => ['nullable', 'numeric', 'gte:0', 'max:100'],
            'extended_payment_days'  => ['nullable', 'integer', 'gte:0'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
