<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  PostFacilityLiabilityChargeRequest
//
//  Validates FacilitySettlementController::postCharge(). Shape only —
//  the domain rules (facility must be OD-family, amount > 0) live in
//  FacilitySettlementEngine::postLiabilityCharge().
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PostFacilityLiabilityChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'charge_type'        => ['required', 'in:interest,highest_debit_balance,admin_fee'],
            'period_label'       => ['required', 'string', 'max:20'],
            'charge_date'        => ['required', 'date'],
            'theoretical_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount'      => ['required', 'numeric', 'min:0.01'],
            'bank_account_id'    => ['nullable', 'integer'],
            'override_reason'    => ['nullable', 'string', 'max:500'],
        ];
    }
}
