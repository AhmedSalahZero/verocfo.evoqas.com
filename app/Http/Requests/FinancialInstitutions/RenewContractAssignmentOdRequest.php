<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewContractAssignmentOdRequest
//
//  Validates a Facility Renewal for an existing OD Against Contract/SO
//  Assignment facility. Same shape as RenewCleanOverdraftRequest, plus
//  max_lending_limit_per_contract. No tier array — unlike CP OD, this
//  facility type's lending % is locked per-contract at assignment
//  time, not read from a shared rate card, so there is nothing here
//  for a renewal to re-supply.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewContractAssignmentOdRequest extends FormRequest
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
            'max_lending_limit_per_contract'     => ['nullable', 'numeric', 'gt:0'],
            'borrowing_rate'                     => ['nullable', 'numeric', 'gte:0'],
            'bank_margin_rate'                   => ['nullable', 'numeric', 'gte:0'],
            'hdb_rate'                            => ['nullable', 'numeric', 'gte:0'],
            'admin_fee_rate'                      => ['nullable', 'numeric', 'gte:0'],
            'settled_max_days'                    => ['nullable', 'integer', 'gte:0'],
            'notes'                                => ['nullable', 'string', 'max:1000'],
        ];
    }
}
