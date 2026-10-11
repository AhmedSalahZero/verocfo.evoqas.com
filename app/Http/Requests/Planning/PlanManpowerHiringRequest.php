<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerHiringRequest
//
//  Just a headcount count per plan year — confirmed simple design,
//  no per-hire salary or month-level detail (spec, Manpower decision).
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanManpowerHiringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_year'        => ['required', 'integer', 'min:1'],
            'new_hires_count'  => ['required', 'integer', 'min:0'],
        ];
    }
}
