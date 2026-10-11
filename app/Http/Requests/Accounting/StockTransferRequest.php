<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockTransferRequest
//
//  Validates a manual stock transfer between two inventory locations.
//  No cost input — a transfer never changes company-wide WAC, it
//  only moves quantity between locations (see StockLedgerService::
//  transfer()).
//
//  FIX (July 2026 audit — Building Bible Rule 2.5): product_id and
//  the two location_id fields previously used a bare `exists:` rule,
//  which only checks that the ID exists ANYWHERE on the platform —
//  not that it belongs to the current company. A tampered request
//  could reference another company's product or location and pass
//  validation. Now scoped with Rule::exists()->where('company_id', ...)
//  so a cross-tenant ID fails validation before it ever reaches the
//  service layer. StockLedgerService itself also re-checks ownership
//  as a second, independent guard — see the FIX note there.
//
//  2026-09 — added optional project_id / sales_order_id, so a
//  transfer TO a project site can carry the project's tag forward
//  (see StockLedgerService::transfer()'s own header for why this is
//  a deliberate, user-declared field, not derived automatically).
//  Both nullable and independent — mirrors goods_receipt_note_lines'
//  own project_id/sales_order_id pattern exactly.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');

        return [
            'product_id'       => [
                'required',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'from_location_id' => [
                'required',
                Rule::exists('inventory_locations', 'id')->where('company_id', $companyId),
                'different:to_location_id',
            ],
            'to_location_id'   => [
                'required',
                Rule::exists('inventory_locations', 'id')->where('company_id', $companyId),
            ],
            'quantity'         => ['required', 'numeric', 'gt:0'],
            'movement_date'    => ['nullable', 'date'],
            'project_id'       => [
                'nullable',
                Rule::exists('projects', 'id')->where('company_id', $companyId),
            ],
            'sales_order_id'   => [
                'nullable',
                Rule::exists('sales_orders', 'id')->where('company_id', $companyId),
            ],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
