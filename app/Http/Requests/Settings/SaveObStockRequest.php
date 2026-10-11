<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SaveObStockRequest
//
//  Validates the Inventory Stock tab on Opening Balances.
//  Split out of OpeningBalanceController::saveStock() (July 2026 audit).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SaveObStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'uom_id'      => ['required', 'integer', 'exists:units_of_measure,id'],
            'quantity'    => ['required', 'numeric', 'min:0.0001'],
            'total_value' => ['required', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
