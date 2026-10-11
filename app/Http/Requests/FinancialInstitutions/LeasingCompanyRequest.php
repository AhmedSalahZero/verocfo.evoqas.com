<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingCompanyRequest
//
//  Validates create and update requests for Leasing Company master
//  records. Extracted from LeasingController::companyRules() — was
//  previously validated inline in the controller (Rule 3.2
//  exception), moved here to match the house style used elsewhere.
//  No rule changed — this is a straight move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LeasingCompanyRequest extends FormRequest
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
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'email'          => ['nullable', 'email', 'max:150'],
            'is_active'      => ['boolean'],
        ];
    }
}
