<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanCollectionTranchesRequest
//
//  Replaces the full tranche set for one (plan_product, market) pair.
//  Percentages summing to 100 is enforced in PlanSalesService (a
//  business rule, not just an input-shape check), not repeated here.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanCollectionTranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'market'                        => ['required', 'in:local,export'],
            'tranches'                      => ['required', 'array', 'min:1'],
            'tranches.*.pct'                => ['required', 'numeric', 'min:0', 'max:100'],
            'tranches.*.days_after_sale'    => ['required', 'integer', 'min:0'],
        ];
    }
}
