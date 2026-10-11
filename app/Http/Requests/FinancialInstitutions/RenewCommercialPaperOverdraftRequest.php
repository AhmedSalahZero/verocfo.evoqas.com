<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewCommercialPaperOverdraftRequest
//
//  Validates a Facility Renewal for an existing OD Against Commercial
//  Paper facility. Same shape as RenewCleanOverdraftRequest, plus
//  max_lending_limit_per_customer and a REQUIRED full 'tiers' array —
//  unlike every other renewable field here, tiers can't be left blank
//  to "carry forward": an empty schedule would mean every cheque
//  deposited after this renewal silently gets a zero lending rate.
//  Ported from CashVero's RenewOverdraftAgainstCommercialPaperRequest.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewCommercialPaperOverdraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date'                   => ['required', 'date'],
            'review_date'                       => ['required', 'date', 'after:effective_date'],
            'facility_limit'                     => ['nullable', 'numeric', 'gt:0'],
            'max_lending_limit_per_customer'     => ['nullable', 'numeric', 'gt:0'],
            'borrowing_rate'                     => ['nullable', 'numeric', 'gte:0'],
            'bank_margin_rate'                   => ['nullable', 'numeric', 'gte:0'],
            'hdb_rate'                            => ['nullable', 'numeric', 'gte:0'],
            'admin_fee_rate'                      => ['nullable', 'numeric', 'gte:0'],
            'settled_max_days'                    => ['nullable', 'integer', 'gte:0'],
            'notes'                                => ['nullable', 'string', 'max:1000'],

            'tiers'                                 => ['required', 'array', 'min:1'],
            'tiers.*.due_within_days'                => ['required', 'integer', 'gt:0'],
            'tiers.*.lending_rate_pct'                => ['required', 'numeric', 'gt:0'],
        ];
    }
}
