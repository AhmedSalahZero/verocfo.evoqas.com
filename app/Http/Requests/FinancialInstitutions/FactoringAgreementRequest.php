<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringAgreementRequest
//
//  Validates create and update requests for Factoring Agreements
//  (Direct with/without recourse + Reverse). Extracted from
//  FactoringController::agreementRules() — was previously validated
//  inline in the controller (Rule 3.2 exception), moved here to
//  match the house style used elsewhere. No rule changed — this is
//  a straight move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class FactoringAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'factoring_company_id'  => ['required', 'exists:factoring_companies,id'],
            'agreement_reference'   => ['nullable', 'string', 'max:100'],
            'agreement_type'        => ['required', 'in:direct,reverse'],
            'recourse_type'         => ['nullable', 'in:with_recourse,without_recourse'],
            'borrowing_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'discount_margin_rate'  => ['required', 'numeric', 'min:0', 'max:100'],
            'discount_rate'         => ['required', 'numeric', 'min:0', 'max:100'],
            'follows_corridor_rate' => ['boolean'],
            'coa_account_id'        => ['required', 'exists:chart_of_accounts,id'],
            'eligible_partner_id'   => ['nullable', 'exists:partners,id'],
            'extended_payment_days' => ['nullable', 'integer', 'min:0'],
            'start_date'            => ['nullable', 'date'],
            'end_date'              => ['nullable', 'date', 'after:start_date'],
            'notes'                 => ['nullable', 'string'],
        ];
    }
}
