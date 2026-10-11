<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ImportLeaseChequesRequest
//
//  Validates LeasingController::importCheques() — the Excel/CSV
//  upload used as an alternative to manually typing the cheque
//  schedule.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ImportLeaseChequesRequest extends FormRequest
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
