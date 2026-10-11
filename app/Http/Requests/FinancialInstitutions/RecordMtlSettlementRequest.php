<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RecordMtlSettlementRequest
//
//  Validates MtlController::recordSettlement() — a real payment
//  against a schedule line. current_account_id is required here
//  directly on the transaction row (not inferred from the parent
//  facility) — see Building Bible Rule 31.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordMtlSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settlement_date'    => ['required', 'date'],
            'settlement_amount'  => ['required', 'numeric', 'min:0.01'],
            'current_account_id' => ['required', 'exists:bank_accounts,id'],
            'notes'              => ['nullable', 'string'],
        ];
    }
}
