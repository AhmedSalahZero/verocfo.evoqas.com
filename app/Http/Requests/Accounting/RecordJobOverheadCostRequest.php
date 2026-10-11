<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RecordJobOverheadCostRequest
//
//  Validates a progressive overhead cost posting against an
//  in-progress Job Order (the flat, non-Piece path), against a
//  specific pool — the same shared OverheadPool table Mass Production
//  uses. Only the base quantity is user input — cost is now
//  calculated (Manufacturing Variance Closing Design v1.0, Section
//  0 / Step 1).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordJobOverheadCostRequest extends FormRequest
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
