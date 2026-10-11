<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitAssemblyRequest
//
//  Validates the "assemble N units of this kit" action.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class KitAssemblyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kit_recipe_id'         => ['required', 'exists:kit_recipes,id'],
            'quantity'              => ['required', 'numeric', 'min:0.0001'],
            'inventory_location_id' => ['required', 'exists:inventory_locations,id'],
            'assembly_date'         => ['nullable', 'date'],
            'notes'                 => ['nullable', 'string'],
        ];
    }
}
