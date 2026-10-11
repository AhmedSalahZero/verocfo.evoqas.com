<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingContractRequest
//
//  Validates create and update requests for Leasing Contracts,
//  including the manually-entered cheque schedule. Extracted from
//  LeasingController::contractRules() — was previously validated
//  inline in the controller (Rule 3.2 exception), moved here to
//  match the house style used elsewhere. No rule changed — this is
//  a straight move, not a re-design.
//
//  Note on 'cheques.*.bank_account_id': added as nullable here to
//  match migration 2026_01_01_000081 (Building Bible Rule 31) — every
//  cheque should carry its own bank account. Marked nullable rather
//  than required so this Request doesn't newly reject any existing
//  in-flight form submission; the Vue form should be updated to
//  collect it going forward.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LeasingContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leasing_company_id'   => ['required', 'exists:leasing_companies,id'],
            'contract_reference'   => ['nullable', 'string', 'max:100'],
            'entry_type'           => ['required', 'in:new,existing'],
            'contract_type'        => ['required', 'in:operational,capital'],
            'asset_description'    => ['required', 'string', 'max:300'],
            'currency_id'          => ['required', 'exists:currencies,id'],
            'exchange_rate'        => ['required', 'numeric', 'min:0'],
            'accounting_treatment' => ['nullable', 'in:expense_a,rou_b'],
            'expense_account_id'   => ['nullable', 'exists:chart_of_accounts,id'],
            'total_contract_value' => ['required', 'numeric', 'min:0'],
            'installment_interval' => ['required', 'in:monthly,quarterly,semi_annual,annual'],
            'installment_amount'   => ['required', 'numeric', 'min:0'],
            'duration_months'      => ['required', 'integer', 'min:1'],
            'borrowing_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'start_date'           => ['required', 'date'],
            'first_payment_date'   => ['nullable', 'date'],
            'total_financed_value' => ['nullable', 'numeric', 'min:0'],
            'payment_frequency'    => ['nullable', 'in:monthly,quarterly'],
            'bank_liability_account_id' => ['required', 'exists:chart_of_accounts,id'],
            'bank_account_id'      => ['required', 'exists:bank_accounts,id'],
            'notes'                => ['nullable', 'string'],

            // Fix (July 2026 — Lease Amortization Engine): only relevant
            // when entry_type = 'existing' — same principle as Opening
            // Balances elsewhere, the user enters where things stand
            // today instead of the system recalculating from scratch.
            'opening_balance_amount'         => ['required_if:entry_type,existing', 'nullable', 'numeric', 'min:0'],
            'opening_balance_as_of_date'     => ['required_if:entry_type,existing', 'nullable', 'date'],
            'opening_remaining_installments' => ['required_if:entry_type,existing', 'nullable', 'integer', 'min:1'],

            // ── Cheque schedule repeater ────────────────────────────
            'cheques'                     => ['nullable', 'array'],
            'cheques.*.payment_date'      => ['required', 'date'],
            'cheques.*.beginning_balance' => ['nullable', 'numeric'],
            'cheques.*.cheque_number'     => ['required', 'string', 'max:50'],
            'cheques.*.cheque_amount'     => ['required', 'numeric', 'min:0'],
            'cheques.*.principal_amount'  => ['required', 'numeric', 'min:0'],
            'cheques.*.interest_amount'   => ['required', 'numeric', 'min:0'],
            'cheques.*.end_balance'       => ['nullable', 'numeric'],
            'cheques.*.bank_account_id'   => ['nullable', 'exists:bank_accounts,id'],
        ];
    }
}
