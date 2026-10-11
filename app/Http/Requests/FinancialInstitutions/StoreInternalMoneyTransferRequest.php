<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  StoreInternalMoneyTransferRequest
//
//  Validates InternalMoneyTransferController::store(). Deeper cross-
//  field checks (leg type must match its own account column, same
//  account on both sides, period-closed) live in
//  InternalMoneyTransferService — this only validates shape.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class StoreInternalMoneyTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_leg_type'        => ['required', 'in:bank,safe,facility'],
            'from_bank_account_id' => ['required_if:from_leg_type,bank', 'nullable', 'integer'],
            'from_safe_account_id' => ['required_if:from_leg_type,safe', 'nullable', 'integer'],
            'from_credit_facility_id' => ['required_if:from_leg_type,facility', 'nullable', 'integer', 'exists:credit_facilities,id'],
            'to_leg_type'           => ['required', 'in:bank,safe,facility'],
            'to_bank_account_id'    => ['required_if:to_leg_type,bank', 'nullable', 'integer'],
            'to_safe_account_id'    => ['required_if:to_leg_type,safe', 'nullable', 'integer'],
            'to_credit_facility_id' => ['required_if:to_leg_type,facility', 'nullable', 'integer', 'exists:credit_facilities,id'],
            'currency_id'           => ['required', 'integer', 'exists:currencies,id'],
            'amount'                => ['required', 'numeric', 'min:0.01'],
            'exchange_rate'         => ['nullable', 'numeric', 'min:0.000001'],
            'transfer_date'         => ['required', 'date'],
            'transfer_days'         => ['nullable', 'integer', 'min:0', 'max:90'],
            'comment'               => ['nullable', 'string'],
        ];
    }
}
