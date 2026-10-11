<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  CostCenterRequest
//
//  Validates create and update requests for Cost Centers.
//  On create: name_en required, parent_id optional (null = Main).
//  On update: name_en and name_ar only — code and level immutable.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CostCenterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isEdit = $this->route('costCenter') !== null;

        if ($isEdit) {
            return [
                'name_en' => ['required', 'string', 'max:150'],
                'name_ar' => ['nullable', 'string', 'max:150'],
            ];
        }

        return [
            'name_en'   => ['required', 'string', 'max:150'],
            'name_ar'   => ['nullable', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ];
    }
}
