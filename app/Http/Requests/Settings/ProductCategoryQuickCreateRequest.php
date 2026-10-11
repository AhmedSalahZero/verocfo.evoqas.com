<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  ProductCategoryQuickCreateRequest
//
//  Validates the quick-add modal on the Product form
//  (ProductCategoryController::quickCreate()). Split out of that
//  controller (July 2026 audit) — same fix as ProductCategoryRequest,
//  scoped to a company just like the full create/edit form.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCategoryQuickCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en'   => ['required', 'string', 'max:150'],
            'name_ar'   => ['nullable', 'string', 'max:150'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', app('currentCompanyId')),
            ],
        ];
    }
}
