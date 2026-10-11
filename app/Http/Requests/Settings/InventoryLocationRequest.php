<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  InventoryLocationRequest
//
//  Validates create and update for Inventory Locations.
//  On create: name_en required, parent_id optional.
//  On update: name_en and name_ar only.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class InventoryLocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isEdit = $this->route('location') !== null;

        if ($isEdit) {
            return [
                'name_en' => ['required', 'string', 'max:150'],
                'name_ar' => ['nullable', 'string', 'max:150'],
            ];
        }

        return [
            'name_en'   => ['required', 'string', 'max:150'],
            'name_ar'   => ['nullable', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
        ];
    }
}
