<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanProductRequest
//
//  Adding a product line to a plan — either a real product
//  (product_id set) or typed in fresh, plan-only (product_id null —
//  Decision 9). Shared by both Annual Plan and Business Plan
//  controllers; this step doesn't differ between the two products.
//
//  name/nature/vat_rate/wht_rate are only REQUIRED when product_id is
//  absent (the plan-only path) — when a real product is picked, the
//  server resolves and LOCKS those fields itself from the real
//  Product record (see PlanService::previewRealProduct()) and
//  ignores whatever the browser sends for them, so a locked field
//  can never be spoofed via the request.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'  => ['nullable', 'integer', 'exists:products,id'],
            'name'        => ['required_without:product_id', 'string', 'max:255'],
            'nature'      => ['required_without:product_id', 'in:manufacturing,trading,service'],
            'uom_code'    => ['nullable', 'string', 'max:20'],
            'vat_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'wht_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
