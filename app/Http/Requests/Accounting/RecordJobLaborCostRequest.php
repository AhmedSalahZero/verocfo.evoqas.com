<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RecordJobLaborCostRequest
//
//  Validates a progressive labor cost posting against an
//  in-progress Job Order (the flat, non-Piece path). Only hours are
//  user input — JobOrderService::recordLaborCost() now calculates the
//  cost itself (hours × the current standard labor rate), same
//  mechanism as logPieceOperationCost() already uses (Manufacturing
//  Variance Closing Design v1.0, Section 0 / Step 1).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordJobLaborCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hours'       => ['required', 'numeric', 'min:0.0001'],
            'entry_date'  => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
