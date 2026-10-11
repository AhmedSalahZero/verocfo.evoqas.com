<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderOperationRequest
//
//  Validates adding one Routing step to a Job Order Piece (Section
//  6.2) — Process, the Machine actually used, Labor Category. All
//  three required — logPieceOperationCost() has nothing to compute
//  from without them.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class JobOrderOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'process_id'        => ['required', 'exists:processes,id'],
            'machine_id'        => ['required', 'exists:machines,id'],
            'labor_category_id' => ['required', 'exists:labor_categories,id'],
        ];
    }
}
