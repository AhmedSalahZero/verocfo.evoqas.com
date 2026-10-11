<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RecordLaborCostRequest
//
//  Validates a progressive labor cost posting against an
//  in-progress Production Order. Only hours are user input —
//  ProductionOrderService::recordLaborCost() now calculates the cost
//  itself (hours × the current standard labor rate), same mechanism
//  as JobOrderService::logPieceOperationCost() (Manufacturing Variance
//  Closing Design v1.0, Section 0 / Step 1). No more manual
//  actual_cost — see that document for why.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordLaborCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hours'        => ['required', 'numeric', 'min:0.0001'],
            'entry_date'   => ['nullable', 'date'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
