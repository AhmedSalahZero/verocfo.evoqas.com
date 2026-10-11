<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProductionOrderRequest
//
//  Validates opening a new Production Order. Only the fields the
//  user actually provides at creation — status, standard cost
//  snapshot, order_code, actual dates are all set by
//  ProductionOrderService, never user input.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'                => ['required', 'exists:products,id'],
            // MFG-007 fix (8 Sep 2026) — bill_of_material_id removed.
            // ProductionOrderService::create() always resolves the
            // effective BOM itself from the order's own date via
            // BillOfMaterialService::resolveEffective() — deliberately,
            // so nothing the client sends can override which version
            // was actually in force. This field was validated as
            // required here but never read by the service at all, and
            // the current form (ProductionOrderForm.vue) correctly
            // shows the resolved version as a read-only preview and
            // never submits it — so this stale rule blocked every
            // submission outright.
            'quantity_to_produce'      => ['required', 'numeric', 'min:0.0001'],
            'raw_material_location_id' => ['required', 'exists:inventory_locations,id'],
            'output_location_id'      => ['required', 'exists:inventory_locations,id'],
            'planned_start_date'      => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string'],
        ];
    }
}
