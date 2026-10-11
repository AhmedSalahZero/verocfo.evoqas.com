<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GoodsReceiptNoteRequest
//
//  purchase_order_id is conditionally required based on the current
//  company's grn_policy (Rule confirmed by Mahmoud — per-company
//  preference, set by Super Admin, not a global platform rule):
//    'po_required'  → purchase_order_id required
//    'allow_ad_hoc' → purchase_order_id nullable
//
//  This is the one validation rule in this file that can't be a
//  static array — it depends on the authenticated company, so it's
//  built in rules() from app('currentCompanyId') the same way every
//  other company-scoped decision in this app is resolved (Rule 6 —
//  never trust the request payload for company_id, and by the same
//  principle, never trust the client to know or honor the policy;
//  the server enforces it either way).
//
//  @layer    FormRequest
//  @depends  App\Models\Company
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ModulePermission middleware gates the route itself
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
            'lines.*.quantity'                    => ['required', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                      => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'lines.*.unit_cost'                   => ['required', 'numeric', 'min:0'],
            'lines.*.project_id'                  => ['nullable', 'integer', 'exists:projects,id'],
            'lines.*.sales_order_id'              => ['nullable', 'integer', 'exists:sales_orders,id'],

            // Landing Cost picks — see VeroCFO_Landing_Cost_Design_v1_0.md.
            // Not attached yet at this point (see GrnLandingCostSelection
            // header) — just the draft's intent, validated the same shape
            // GoodsReceiptNoteService::syncLandingCostSelections() expects.
            'landing_cost_selections'                        => ['nullable', 'array'],
            'landing_cost_selections.*.landing_cost_id'      => ['required', 'integer', 'exists:landing_costs,id'],
            'landing_cost_selections.*.allocation_base'      => ['required', 'string', 'in:value,quantity,weight,manual'],
            'landing_cost_selections.*.manual_amounts'       => ['nullable', 'array'],
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_order_id'     => 'Purchase Order',
            'partner_id'            => 'Supplier',
            'inventory_location_id' => 'Receiving Location',
            'received_date'         => 'Received Date',
        ];
    }
}
