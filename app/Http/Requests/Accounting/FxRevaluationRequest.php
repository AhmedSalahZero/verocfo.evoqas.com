<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FxRevaluationRequest
//
//  Validates posting a month-end FX revaluation (Audit H12). Only the
//  date and an optional note come from the browser — every amount is
//  recomputed on the server by FxRevaluationService::post(), never
//  taken from the preview the person looked at.
//
//  @layer    Request
//  @depends  FxRevaluationService
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class FxRevaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ModulePermission middleware gates the route itself
    }

    public function rules(): array
    {
        return [
            'revaluation_date' => ['required', 'date'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['revaluation_date' => 'Month-end date'];
    }
}
