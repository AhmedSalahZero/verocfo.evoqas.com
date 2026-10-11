<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LogJobOperationCostRequest
//
//  Validates logging hours against a Job Order Operation (Section 6).
//  Deliberately NO actual_cost field here — unlike
//  RecordJobLaborCostRequest/RecordJobOverheadCostRequest, cost is
//  always computed automatically by
//  JobOrderService::logPieceOperationCost() from the Operation's own
//  Labor Category / Machine's Overhead Pool rate(s); confirmed with
//  Mahmoud that no manual cost entry exists once a job uses Pieces.
//  Both hour fields are optional individually (a pure-labor or
//  pure-machine step is valid) but at least one must be > 0.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LogJobOperationCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'labor_hours'   => ['nullable', 'numeric', 'min:0'],
            'machine_hours' => ['nullable', 'numeric', 'min:0'],
            'entry_date'    => ['nullable', 'date'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $labor = (float) ($this->input('labor_hours') ?? 0);
            $machine = (float) ($this->input('machine_hours') ?? 0);
            if ($labor <= 0 && $machine <= 0) {
                $validator->errors()->add('labor_hours', 'Enter at least some labor or machine hours.');
            }
        });
    }
}
