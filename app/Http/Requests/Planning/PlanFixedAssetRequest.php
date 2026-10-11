<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanFixedAssetRequest
//
//  Adding a PLANNED future asset purchase. Adding an EXISTING real
//  asset only needs its id (route-bound FixedAsset, no body to
//  validate) — see PlanFixedAssetController::addExisting().
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:200'],
            'cost_basis'           => ['required', 'numeric', 'min:0'],
            'residual_value'       => ['nullable', 'numeric', 'min:0'],
            'useful_life_months'   => ['required', 'integer', 'min:1'],
            'purchase_month'       => ['required', 'integer', 'min:0'],
        ];
    }
}
