<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StandardRateRequest
//
//  Validates create/update for a Standard Rate version. Identity
//  fields (fiscal_year_id, rate_type, overhead_pool_id,
//  labor_category_id) are only required on create —
//  StandardRateService::update() never changes which key a version
//  belongs to, same reasoning as BillOfMaterialRequest stripping
//  product_id from its own update path.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class StandardRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'fiscal_year_id'    => [$isCreate ? 'required' : 'nullable', 'exists:fiscal_years,id'],
            'rate_type'         => [$isCreate ? 'required' : 'nullable', 'in:labor,overhead_pool,labor_category'],
            'overhead_pool_id'  => ['required_if:rate_type,overhead_pool', 'nullable', 'exists:overhead_pools,id'],
            'labor_category_id' => ['required_if:rate_type,labor_category', 'nullable', 'exists:labor_categories,id'],
            'annual_rate'       => ['required', 'numeric', 'min:0'],
            'effective_from'    => ['required', 'date'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ];
    }
}
