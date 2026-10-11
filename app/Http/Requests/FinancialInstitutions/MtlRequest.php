<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlRequest
//
//  Validates create and update requests for Medium Term Loan
//  facilities. Extracted from MtlController::rules() — was
//  previously validated inline in the controller (Rule 3.2
//  exception), moved here to match the house style used elsewhere.
//  No rule changed — this is a straight move, not a re-design.
//
//  Fix (August 2026 — cross-table account number uniqueness):
//  account_number now validated unique per (company, bank) across
//  both bank_accounts and credit_facilities via
//  UniqueBankAccountNumber — a bank account number is one physical
//  thing at the bank regardless of which Vero CFO table it lives in.
//  Still nullable (MTLs don't always have their own operating
//  account) — the rule is a no-op on blank values.
//
//  @layer    Request
//  @depends  App\Rules\UniqueBankAccountNumber
// ══════════════════════════════════════════════════════════════════

use App\Rules\UniqueBankAccountNumber;
use App\Models\FinancialInstitutions\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MtlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $route = $this->route('facility');
        $excludeId = is_object($route) ? $route->id : $route;

        return [
            'bank_id'              => ['required', 'exists:banks,id'],
            'name'                 => ['required', 'string', 'max:200'],
            'entry_type'           => ['required', 'in:new,existing'],
            'start_date'           => ['required', 'date'],
            'review_date'          => ['required', 'date', 'after:start_date'],
            'currency_id'          => ['required', 'exists:currencies,id'],
            'facility_limit'       => ['required', 'numeric', 'min:0'],

            // TRS-002 — a NEW loan posts its drawdown to the general
            // ledger (Dr bank / Cr 211 Long-Term Loans), so the bank
            // account that received the cash has to be stated. An
            // EXISTING loan posts nothing on creation — its liability
            // is already on the books through Opening Balances — so
            // the field is not required there. Same shape as the
            // Clean Overdraft's own bank account link (TRS-001).
            'bank_account_id'      => [
                'required_if:entry_type,new', 'nullable',
                Rule::exists((new BankAccount)->getTable(), 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        // Same bank as the facility. The form's dropdown
                        // only ever offers this bank's accounts, but the
                        // rule has to say so too — a stale selection left
                        // over from switching banks would otherwise link
                        // the loan to an account somewhere else entirely.
                        ->where('bank_id', $this->input('bank_id'))
                        // Same currency: a facility and its account move
                        // one currency between them, never converting.
                        ->where('currency_id', $this->input('currency_id'))
                        ->where('account_type', 'current')
                        ->where('is_active', true)
                ),
            ],
            'account_number'       => [
                'nullable', 'string', 'max:100',
                new UniqueBankAccountNumber($companyId, $this->input('bank_id'), 'credit_facilities', $excludeId),
            ],
            'borrowing_rate'       => ['required', 'numeric', 'min:0'],
            'bank_margin_rate'     => ['required', 'numeric', 'min:0'],
            'tenure_months'        => ['required', 'integer', 'min:1'],
            'installment_interval' => ['required', 'in:monthly,quarterly,semi_annual,annual'],
        ];
    }
}
