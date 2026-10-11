<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OverheadPoolRequest
//
//  Validates create/update for a user-defined overhead pool.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class OverheadPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:150'],
            'allocation_base' => ['required', 'in:labor_hours,machine_hours,production_units'],
            'is_active'       => ['boolean'],
        ];
    }
}
