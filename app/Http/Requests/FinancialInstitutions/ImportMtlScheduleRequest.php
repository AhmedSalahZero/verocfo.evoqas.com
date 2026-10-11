<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ImportMtlScheduleRequest
//
//  Validates MtlController::importSchedule() — the Excel/CSV upload
//  used as an alternative to manually typing the loan schedule.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ImportMtlScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }
}
