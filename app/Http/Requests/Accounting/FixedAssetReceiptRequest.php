<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetReceiptRequest
//
//  Fixed Asset Receipts get their own dedicated page (Accounting →
//  Fixed Assets → Receipts, confirmed with Mahmoud — mixing Fixed
//  Asset receiving into the general Goods Receipt Note screen, with
//  its project/sales-order tagging and UOM picker that don't apply
//  to owned equipment, confused the user the same way the combined
//  Products & Services form did). Under the hood this still creates
//  a real GoodsReceiptNote row — the same GRNI-clearing, 3-way-match,
//  and reversal engine (GoodsReceiptNoteService) stays exactly as-is
//  — this Request is a stricter, simplified subset of
//  GoodsReceiptNoteRequest: no project_id/sales_order_id, and
//  quantity must be a whole number (a Fixed Asset Receipt line always
//  becomes one or more individual, whole assets — see
//  FixedAssetService::receiveFromGrnLine() — so a fractional quantity
//  would silently truncate there; better to block it here).
//
//  @layer    FormRequest
//  @depends  App\Models\Company, App\Models\Product
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FixedAssetReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');
        $company   = Company::withoutGlobalScopes()->find($companyId);
        $poRequired = ! $company?->allowsAdHocGrn();

        return [
            'purchase_order_id'     => [$poRequired ? 'required' : 'nullable', 'integer', 'exists:purchase_orders,id'],
            'partner_id'            => ['required', 'integer', 'exists:partners,id'],
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'received_date'         => ['required', 'date'],
            'exchange_rate'         => ['nullable', 'numeric', 'min:0.000001'],
            'supplier_reference'    => ['nullable', 'string', 'max:100'],
            'internal_notes'        => ['nullable', 'string'],

            'lines'                              => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id'     => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.product_id'                 => ['required', 'integer', 'exists:products,id'],
            'lines.*.description'                => ['nullable', 'string', 'max:500'],
            // Whole number only — see class header.
            'lines.*.quantity'                    => ['required', 'integer', 'min:1'],
            'lines.*.uom_id'                      => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'lines.*.unit_cost'                   => ['required', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_order_id'     => 'Purchase Order',
            'partner_id'            => 'Supplier',
            'inventory_location_id' => 'Location',
            'received_date'         => 'Received Date',
        ];
    }

    /**
     * Defense in depth (Rule 2.5 principle) — never trust the client
     * to only ever offer Fixed Asset Type products in the line-item
     * dropdown. Blocks the request outright if any line's product
     * isn't actually nature = 'fixed_asset', the same way this
     * dedicated page must never silently create or touch an ordinary
     * inventory receipt.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('lines', []) as $index => $line) {
                $productId = $line['product_id'] ?? null;
                if (! $productId) continue;

                $isFixedAsset = Product::where('id', $productId)->where('nature', Product::NATURE_FIXED_ASSET)->exists();
                if (! $isFixedAsset) {
                    $validator->errors()->add("lines.{$index}.product_id", 'This product is not a Fixed Asset Type — it cannot be received on this page.');
                }
            }
        });
    }
}
