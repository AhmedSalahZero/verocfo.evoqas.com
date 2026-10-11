<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  ProductRequest
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isEdit = $this->route('product') !== null;

        $base = [
            'name_en'           => ['required','string','max:255'],
            'name_ar'           => ['nullable','string','max:255'],
            'barcode'           => ['nullable','string','max:50'],
            'description'       => ['nullable','string'],
            'category_id'       => ['required','integer','exists:product_categories,id'],
            'usage_types'       => ['required','array','min:1'],
            'usage_types.*'     => ['string','in:saleable,raw_material,consumable'],
            // 'nature' stays nullable here (a raw_material-only or
            // consumable-only product legitimately has none) — the
            // real guard is withValidator() below, which requires it
            // specifically when 'saleable' is selected. Fixed July
            // 2026 alongside the Vue watcher bug that let this reach
            // the server as null in the first place (see
            // ProductService::resolveNature()).
            'nature'            => ['nullable','string','in:tradable,manufactured,service'],
            'primary_uom_id'    => ['required','integer','exists:units_of_measure,id'],
            'alternate_uoms'    => ['nullable','array','max:3'],
            'alternate_uoms.*.uom_id'          => ['required','integer','exists:units_of_measure,id'],
            'alternate_uoms.*.conversion_rate' => ['required','numeric','min:0.000001'],
            'alternate_uoms.*.use_for_purchase'=> ['boolean'],
            'alternate_uoms.*.use_for_sales'   => ['boolean'],
            'weight'            => ['nullable','numeric','min:0'],
            'weight_uom_id'     => ['nullable','integer','exists:units_of_measure,id'],
            'reorder_level'     => ['nullable','numeric','min:0'],
            'reorder_qty'       => ['nullable','numeric','min:0'],
        ];

        if (! $isEdit) {
            $base['use_auto_code'] = ['required','boolean'];
            $base['code']          = ['required_if:use_auto_code,false','nullable','string','max:30'];
        }

        return $base;
    }

    /**
     * Only rejects a missing `nature` when it's genuinely ambiguous —
     * i.e. the company's type allows more than one Nature option, so
     * there's no safe default to fall back on. When there's exactly
     * one valid option (e.g. a pure Trading company only ever has
     * "tradable"), ProductService::resolveNature() fills it in
     * automatically — no need to hard-block the save for that case.
     * Added July 2026 alongside the Vue watcher fix that let `nature`
     * reach the server as null in the first place.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $usageTypes = $this->input('usage_types', []);
            if (! in_array('saleable', $usageTypes, true) || $this->filled('nature')) {
                return;
            }

            $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
            $company   = $companyId ? \App\Models\Company::withoutGlobalScopes()->find($companyId) : null;

            $optionCount = match (true) {
                $company?->isManufacturing() => 3,
                $company?->hasInventory() && $company?->hasMixedInvoices() => 2,
                $company?->hasInventory() => 1,
                default => 1,
            };

            if ($optionCount > 1) {
                $validator->errors()->add('nature', 'Please select a Nature for this saleable product.');
            }
        });
    }
}
