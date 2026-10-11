<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  ProductCategoryRequest
//
//  Validates create and update requests for Product Categories.
//  Split out of ProductCategoryController::validateRequest() (July
//  2026 audit) — inline $request->validate() in a controller is a
//  Building Bible Rule 3.2 violation, same fix pattern already
//  applied to Partner/Product/CostCenter/etc.
//
//  exists: rules on parent_id / expense_account_id are scoped to
//  the current company (Rule 2.5) — a bare 'exists:table,id' only
//  checks the row exists ANYWHERE on the platform, not that it
//  belongs to this company.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');
        $isEdit    = $this->route('productCategory') !== null;

        $rules = [
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
        ];

        if (! $isEdit) {
            $rules['parent_id'] = [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId),
            ];
        }

        $rules['cost_treatment']     = ['nullable', 'in:overhead,period_expense'];
        $rules['expense_account_id'] = [
            'nullable', 'integer',
            Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId),
        ];

        return $rules;
    }
}
