<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RecordOverheadCostRequest
//
//  Validates a progressive overhead cost posting against an
//  in-progress Production Order, against a specific pool. Only the
//  base quantity (labor or machine hours, per the pool's own
//  allocation base) is user input — ProductionOrderService now
//  calculates the cost itself (Manufacturing Variance Closing Design
//  v1.0, Section 0 / Step 1). No more manual actual_cost.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordOverheadCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'overhead_pool_id' => ['required', 'exists:overhead_pools,id'],
            'base_quantity'    => ['required', 'numeric', 'min:0.0001'],
            'entry_date'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
