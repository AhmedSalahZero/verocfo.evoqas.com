<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  SaveMtlScheduleRequest
//
//  Validates MtlController::saveSchedule() — the manually-entered
//  loan schedule lines (no auto-amortization, entered or imported
//  directly — see CreditFacility model header, migration 000078).
//
//  v1.1 — added lines.*.id (nullable) so MtlService::saveSchedule()
//  can tell an existing, already-saved row (which might be locked
//  by a real settlement) apart from a brand-new, never-saved row.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SaveMtlScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines'                     => ['array'],
            'lines.*.id'                => ['nullable', 'integer'],
            'lines.*.due_date'          => ['required', 'date'],
            'lines.*.beginning_balance' => ['required', 'numeric', 'min:0'],
            'lines.*.schedule_payment'  => ['required', 'numeric', 'min:0'],
            'lines.*.interest_amount'   => ['required', 'numeric', 'min:0'],
            'lines.*.principal_amount'  => ['required', 'numeric', 'min:0'],
            'lines.*.end_balance'       => ['required', 'numeric'],
        ];
    }
}
