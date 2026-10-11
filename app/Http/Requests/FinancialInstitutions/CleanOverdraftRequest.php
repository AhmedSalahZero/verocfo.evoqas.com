<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CleanOverdraftRequest
//
//  Validates create and update requests for Clean Overdraft facilities.
//  Extracted from CleanOverdraftController::rules() — was previously
//  validated inline in the controller (Rule 3.2 exception), moved
//  here to match the house style used elsewhere. No rule changed —
//  this is a straight move, not a re-design.
//
//  Fix (August 2026 — cross-table account number uniqueness):
//  account_number now validated unique per (company, bank) across
//  both bank_accounts and credit_facilities via
//  UniqueBankAccountNumber — a bank account number is one physical
//  thing at the bank regardless of which Vero CFO table it lives in.
//
//  @layer    Request
//  @depends  App\Rules\UniqueBankAccountNumber
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Rules\UniqueBankAccountNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CleanOverdraftRequest extends FormRequest
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
            // ── Section 1 — Contract Main Information ─────────────
            'bank_id'               => ['required', 'exists:banks,id'],
            'start_date'            => ['required', 'date'],
            'review_date'           => ['required', 'date', 'after:start_date'],
            'account_number'        => [
                'required', 'string', 'max:100',
                new UniqueBankAccountNumber($companyId, $this->input('bank_id'), 'credit_facilities', $excludeId),
            ],
            'currency_id'           => ['required', 'exists:currencies,id'],
            'coa_account_id'        => ['required', 'exists:chart_of_accounts,id'],
            // TRS-001 fix (8 Sep 2026) — a Clean Overdraft draws down
            // and repays through a real current account at the same
            // bank; without this link, Internal Money Transfer and
            // every payment service correctly refuse to use the
            // facility as a money leg at all (confirmed: every demo
            // seeder in the repo patched this column by hand, which a
            // real user has no way to do). Scoped to the same company,
            // same bank, and same currency as the facility itself, so
            // it can't be pointed at an unrelated account.
            'bank_account_id'       => [
                'required',
                Rule::exists((new BankAccount)->getTable(), 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('bank_id', $this->input('bank_id'))
                        ->where('currency_id', $this->input('currency_id'))
                        ->where('account_type', 'current')
                        ->where('is_active', true)
                ),
            ],

            // ── Section 2 — Terms & Conditions ─────────────────────
            'facility_limit'        => ['required', 'numeric', 'min:0'],
            'outstanding_balance'   => ['required', 'numeric', 'min:0'],
            'balance_date'          => ['required', 'date'],
            'borrowing_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'bank_margin_rate'      => ['required', 'numeric', 'min:0', 'max:100'],
            'min_interest_rate'     => ['required', 'numeric', 'min:0', 'max:100'],
            'hdb_rate'              => ['required', 'numeric', 'min:0', 'max:100'],
            'admin_fee_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'settled_max_days'      => ['required', 'integer', 'min:0'],
            'follows_corridor_rate' => ['boolean'],
            'purpose'               => ['nullable', 'string', 'max:500'],

            // ── Section 3 — Outstanding Breakdown ──────────────────
            // Only required rows when outstanding_balance > 0 — enforced in service
            'breakdown'             => ['nullable', 'array'],
            'breakdown.*.amount'    => ['required_with:breakdown', 'numeric', 'min:0.01'],
            'breakdown.*.due_date'  => ['required_with:breakdown', 'date'],
        ];
    }
}
