<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ConsumeJobMaterialRequest
//
//  Validates consuming a raw material component into an in-progress
//  Job Order. Location is NOT here — always the job's own
//  raw_material_location_id, same principle as
//  RecordMaterialConsumptionRequest (Mass Production).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ConsumeJobMaterialRequest extends FormRequest
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
