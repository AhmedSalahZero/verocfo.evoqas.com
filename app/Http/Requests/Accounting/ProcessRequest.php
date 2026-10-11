<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProcessRequest
//
//  Validates create/update for a user-defined manufacturing process.
//  Mirrors LaborCategoryRequest exactly.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ProcessRequest extends FormRequest
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
