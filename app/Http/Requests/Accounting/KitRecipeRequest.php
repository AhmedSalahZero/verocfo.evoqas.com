<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitRecipeRequest
//
//  Validates create/update for a Kit recipe header + component
//  lines. product_id is only required on create — KitRecipeService::
//  update() never changes which product a recipe belongs to (same
//  reasoning as BillOfMaterialRequest stripping product_id from its
//  own update path), so it's not required here on the update route;
//  the controller simply never reads it back out on update.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class KitRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'exists:products,id'],
            'notes'      => ['nullable', 'string'],

            'lines'                                 => ['required', 'array', 'min:1'],
            'lines.*.component_product_id'          => ['required', 'exists:products,id'],
            'lines.*.quantity_per_kit'              => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
