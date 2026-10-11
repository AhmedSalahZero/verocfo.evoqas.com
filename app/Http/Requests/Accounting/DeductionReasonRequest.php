<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeductionReasonRequest
//
//  Validates a Deduction Reason. `behavior` is required on every
//  save, including update — confirmed with Mahmoud: a reason's
//  behavior can be corrected, but note that PayrollRunService::
//  recalculateLine() reads it live from the reason, not a per-line
//  snapshot, so editing behavior after a reason has already been
//  used on a DRAFT run's line items will reclassify those existing
//  lines too on next recalculation. Flagged as a known rough edge —
//  fine for a first slice, worth a snapshot field if this proves to
//  matter in practice.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class DeductionReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en'  => ['required', 'string', 'max:255'],
            'name_ar'  => ['nullable', 'string', 'max:255'],
            'behavior' => ['required', 'in:earned_reduction,payout_penalty'],
        ];
    }
}
