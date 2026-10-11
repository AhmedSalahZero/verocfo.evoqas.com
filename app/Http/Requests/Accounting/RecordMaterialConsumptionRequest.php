<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RecordMaterialConsumptionRequest
//
//  Validates consuming a raw material component into an in-progress
//  Production Order. Location is NOT here — it's always the order's
//  own raw_material_location_id, never chosen per consumption.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordMaterialConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'component_product_id' => ['required', 'exists:products,id'],
            'quantity'              => ['required', 'numeric', 'min:0.0001'],
            'entry_date'            => ['nullable', 'date'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }
}
