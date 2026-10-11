<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetRequest
//
//  Validates editing an individual FixedAsset's OWN register fields
//  (name, serial number, residual value, useful life, depreciation
//  start date, location, notes). Deliberately does NOT include
//  cost_basis — that only ever changes through a traced cost line
//  (FixedAssetService::addCostLine()/confirmCostLine()), never a
//  direct edit here.
//
//  @layer    Request
//  @depends  FixedAsset
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class FixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:200'],
            'serial_number'           => ['nullable', 'string', 'max:150'],
            'residual_value'          => ['required', 'numeric', 'min:0'],
            'useful_life_months'      => ['required', 'integer', 'min:1'],
            'depreciation_start_date' => ['required', 'date'],
            'inventory_location_id'   => ['nullable', 'exists:inventory_locations,id'],
            'notes'                   => ['nullable', 'string'],
        ];
    }
}
