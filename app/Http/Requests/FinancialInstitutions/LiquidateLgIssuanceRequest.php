<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LiquidateLgIssuanceRequest
//
//  Validates LgIssuanceController::liquidate() (consumes cash cover
//  first, shortfall to a chosen bank account).
//
//  Fix (July 2026 audit): added liquidation_expense_account_id —
//  the claim is a real loss and needs a real Dr account; the user
//  picks it here (the account itself must be postable, checked by
//  the service). Required, since every liquidation books a claim
//  against some expense/loss account — there's no valid liquidation
//  without one.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LiquidateLgIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'liquidated_at'                    => ['required', 'date'],
            'claim_amount'                      => ['nullable', 'numeric', 'min:0'],
            'liquidation_expense_account_id'   => ['required', 'exists:chart_of_accounts,id'],
            'liquidation_shortfall_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes'                             => ['nullable', 'string'],
        ];
    }
}
