<?php

namespace App\Http\Requests\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectRequest
//
//  Validates create and update requests for Projects (Contracts).
//  Extracted from ProjectController::rules() — was previously
//  validated inline in the controller (Rule 3.2 exception), moved
//  here to match the house style already used by PartnerRequest,
//  ProductRequest, etc. No rule changed — this is a straight move,
//  not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en'        => ['required', 'string', 'max:200'],
            'name_ar'        => ['nullable', 'string', 'max:200'],
            'description'    => ['nullable', 'string'],
            'partner_id'     => ['required', 'exists:partners,id'],
            'contract_value' => ['required', 'numeric', 'min:0'],
            'currency_id'    => ['required', 'exists:currencies,id'],
            'exchange_rate'  => ['required', 'numeric', 'min:0'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['required', 'date', 'after:start_date'],
        ];
    }
}
