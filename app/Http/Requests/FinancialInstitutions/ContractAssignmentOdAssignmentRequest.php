<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ContractAssignmentOdAssignmentRequest
//
//  Validates adding a pledged Project/SO assignment to an existing
//  OD Against Contract/SO facility. Extracted from
//  ContractAssignmentOdController::assignmentRules() — was
//  previously validated inline in the controller (Rule 3.2
//  exception), moved here to match the house style used elsewhere.
//  No rule changed — this is a straight move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ContractAssignmentOdAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'            => ['required', 'exists:partners,id'],
            'collateral_type'       => ['required', 'in:project,sales_order'],
            'collateral_project_id' => ['required_if:collateral_type,project', 'nullable', 'exists:projects,id'],
            'collateral_so_id'      => ['required_if:collateral_type,sales_order', 'nullable', 'exists:sales_orders,id'],
            'assignment_date'       => ['required', 'date'],
            'lending_pct'           => ['required', 'numeric', 'min:0.01', 'max:100'],
            // max_lending_limit removed — cap is on the facility itself
            // (max_lending_limit_per_contract), not per assignment.
        ];
    }
}
