<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LaborCategoryRequest
//
//  Validates create/update for a user-defined labor category. Mirrors
//  OverheadPoolRequest exactly, minus allocation_base — see
//  LaborCategory's own header for why that concept doesn't apply here.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LaborCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ];
    }
}
