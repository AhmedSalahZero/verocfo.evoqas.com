<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankRequest
//
//  Validates create and update requests for Bank master records.
//  Extracted from BankController::bankRules() — was previously
//  validated inline in the controller (Rule 3.2 exception), moved
//  here to match the house style used elsewhere. No rule changed —
//  this is a straight move, not a re-design.
//
//  Recovery note: bankRules() was briefly, accidentally deleted
//  along with an unrelated dead-code cleanup earlier in the same
//  audit session (the deletion range was too wide). Recovered here
//  verbatim from the original source before extraction — no rule
//  content was lost or altered.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class BankRequest extends FormRequest
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
            'branch_name'    => ['nullable', 'string', 'max:200'],
            'branch_address' => ['nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'email'          => ['nullable', 'email', 'max:150'],
            'swift_code'     => ['nullable', 'string', 'max:20'],
            'is_active'      => ['boolean'],
        ];
    }
}
