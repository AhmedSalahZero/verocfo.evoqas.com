<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeliveryNoteRequest
//
//  Validates create/update for Delivery Notes. Deeper checks
//  (sufficient stock at the location, quantity not exceeding what's
//  left to deliver on the SO line) are enforced in
//  DeliveryNoteService/StockLedgerService, not here — same
//  defense-in-depth split used throughout this codebase.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class DeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_order_id'    => ['required', 'integer', 'exists:sales_orders,id'],
            'partner_id'        => ['required', 'integer', 'exists:partners,id'],
            'delivery_date'     => ['required', 'date'],
            'internal_notes'    => ['nullable', 'string'],

            'lines'                             => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id'        => ['required', 'integer', 'exists:sales_order_lines,id'],
            'lines.*.location_id'                => ['required', 'integer', 'exists:inventory_locations,id'],
            'lines.*.description'                => ['nullable', 'string', 'max:500'],
            'lines.*.quantity'                    => ['required', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                      => ['nullable', 'integer', 'exists:units_of_measure,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sales_order_id'   => 'Sales Order',
            'partner_id'       => 'Customer',
            'delivery_date'    => 'Delivery Date',
            'lines.*.location_id' => 'Shipping From',
        ];
    }
}
