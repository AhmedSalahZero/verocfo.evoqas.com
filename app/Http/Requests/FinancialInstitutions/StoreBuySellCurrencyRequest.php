<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  StoreBuySellCurrencyRequest
//
//  Validates BuySellCurrencyController::store()/update() — both
//  always save a draft only (post() is a separate, deliberate step),
//  so this only validates shape, not reference rates or the FX plug.
//  Deeper cross-field checks (leg type matches its own account
//  column, currencies must differ, period-closed) live in
//  BuySellCurrencyService.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class StoreBuySellCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date'      => ['required', 'date'],

            'from_leg_type'         => ['required', 'in:bank,safe'],
            'from_bank_account_id'  => ['required_if:from_leg_type,bank', 'nullable', 'integer'],
            'from_safe_account_id'  => ['required_if:from_leg_type,safe', 'nullable', 'integer'],

            'to_leg_type'           => ['required', 'in:bank,safe'],
            'to_bank_account_id'    => ['required_if:to_leg_type,bank', 'nullable', 'integer'],
            'to_safe_account_id'    => ['required_if:to_leg_type,safe', 'nullable', 'integer'],

            'currency_to_sell_id'   => ['required', 'integer', 'exists:currencies,id', 'different:currency_to_buy_id'],
            'currency_to_sell_amount' => ['required', 'numeric', 'min:0.01'],

            'currency_to_buy_id'    => ['required', 'integer', 'exists:currencies,id'],
            'currency_to_buy_amount' => ['nullable', 'numeric', 'min:0.01'],

            'deal_rate'             => ['required', 'numeric', 'min:0.0000000001'],

            'comment'               => ['nullable', 'string'],
        ];
    }
}
