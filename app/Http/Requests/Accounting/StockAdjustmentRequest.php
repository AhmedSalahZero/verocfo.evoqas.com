<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockAdjustmentRequest
//
//  Validates a manual stock adjustment (found/lost stock, damage,
//  count discrepancy). unit_cost is required only when direction is
//  'in' — StockLedgerService::adjust() needs it to move WAC the same
//  way a receipt would; an 'out' adjustment relieves at the current
//  WAC and needs no cost input.
//
//  notes is required (not just nullable) — an adjustment is a manual
//  override of the system-calculated stock position, so the reason
//  must be on record, same spirit as every other audited financial
//  write in this app.
//
//  FIX (July 2026 audit — Building Bible Rule 2.5): product_id and
//  inventory_location_id previously used a bare `exists:` rule, which
//  only checks the ID exists ANYWHERE on the platform — not that it
//  belongs to the current company. Now scoped with
//  Rule::exists()->where('company_id', ...), matching the same fix
//  applied to StockTransferRequest. StockLedgerService re-checks
//  ownership independently as a second guard.
//
//  MFG-002 write-off fix (confirmed with Mahmoud, 2026-09) —
//  job_order_id is optional and only meaningful when direction is
//  'out': it targets a specific Job Order's own Finished Goods
//  segment (e.g. a customer-specific build damaged before delivery)
//  instead of the pooled company-wide balance. Whether that segment
//  actually holds enough of this product is a business check, left to
//  StockAdjustmentService — this only confirms the Job Order itself
//  is real, belongs to this company, and has actually completed.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use App\Models\JobOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');

        return [
            'product_id'            => [
                'required',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'inventory_location_id' => [
                'required',
                Rule::exists('inventory_locations', 'id')->where('company_id', $companyId),
            ],
            'direction'             => ['required', 'in:in,out'],
            'quantity'              => ['required', 'numeric', 'gt:0'],
            'unit_cost'             => ['required_if:direction,in', 'nullable', 'numeric', 'gt:0'],
            'movement_date'         => ['nullable', 'date'],
            'notes'                 => ['required', 'string', 'max:1000'],
            // Audit M-18 — why, and the stock-count sheet it came from.
            'reason_code'           => ['required', Rule::in(array_keys(\App\Services\Accounting\StockAdjustmentService::REASONS))],
            'count_reference'       => ['required_if:reason_code,count_difference', 'nullable', 'string', 'max:60'],
            'job_order_id'          => [
                'nullable',
                Rule::exists('job_orders', 'id')
                    ->where('company_id', $companyId)
                    ->where('status', JobOrder::STATUS_COMPLETED),
            ],
        ];
    }
}
