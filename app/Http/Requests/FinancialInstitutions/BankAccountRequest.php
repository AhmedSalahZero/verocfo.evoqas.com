<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankAccountRequest
//
//  Validates update requests for a single Bank Account. Extracted
//  from BankController::accountRules() — was previously validated
//  inline in the controller (Rule 3.2 exception), moved here to
//  match the house style used elsewhere.
//
//  Note: create (accountsStore) uses BankAccountsRepeaterRequest
//  instead, which wraps these same field rules under an 'accounts.*.'
//  prefix for the multi-row repeater submission.
//
//  Fix (July 2026 — Time Deposit Lifecycle, confirmed with Mahmoud):
//    - td_auto_renewal rule removed — the field no longer exists,
//      replaced by TimeDepositService::renew().
//    - td_entry_type / td_funding_bank_account_id added.
//    - account_number is now validated unique per (company, bank) —
//      the same account number can exist at two different banks, but
//      never twice at the same bank. Built dynamically in rules()
//      rather than the static fieldRules() array below, since it
//      needs the current company id and (on update) this record's
//      own id to exclude from the uniqueness check.
//
//  Fix (August 2026 — cross-table account number uniqueness): a real
//  bank account number is one physical thing at a bank, whether it's
//  recorded here as a Bank Account or typed onto a credit facility
//  (Clean OD / CP OD / Contract Assignment OD / MTL — all share
//  credit_facilities.account_number). Swapped the single-table
//  Rule::unique() for UniqueBankAccountNumber, which checks both
//  tables. See screenshot bug: two Clean OD facilities under the
//  same bank both showing account number 105001 with no validation
//  ever having blocked it.
//
//  @layer    Request
//  @depends  App\Rules\UniqueBankAccountNumber
// ══════════════════════════════════════════════════════════════════

use App\Rules\UniqueBankAccountNumber;
use Illuminate\Foundation\Http\FormRequest;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = self::fieldRules();
        $rules['account_number'][] = $this->uniqueAccountNumberRule();
        return $rules;
    }

    private function uniqueAccountNumberRule(): UniqueBankAccountNumber
    {
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $route = $this->route('bankAccount');
        $excludeId = is_object($route) ? $route->id : $route;

        return new UniqueBankAccountNumber($companyId, $this->input('bank_id'), 'bank_accounts', $excludeId);
    }

    /**
     * The raw field rules, without any wrapping. Exposed statically so
     * BankAccountsRepeaterRequest can reuse the exact same rules under
     * an 'accounts.*.' prefix for the multi-row create form — mirrors
     * the original accountsRepeaterRules() calling accountRules().
     * Does NOT include the account_number uniqueness rule — that's
     * added separately by each concrete request class, since create
     * vs update need different ignore()/company-id handling.
     */
    public static function fieldRules(): array
    {
        return [
            'bank_id'                      => ['required', 'exists:banks,id'],
            'account_name'                 => ['required', 'string', 'max:200'],
            'account_number'               => ['nullable', 'string', 'max:100'],
            'account_type'                 => ['required', 'in:current,time_deposit,escrow,lg_cash_cover,lc_cash_cover'],
            'currency_id'                  => ['required', 'exists:currencies,id'],
            'coa_account_id'               => ['required', 'exists:chart_of_accounts,id'],
            'interest_rate'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_balance_for_interest' => ['nullable', 'numeric', 'min:0'],
            'td_entry_type'                => ['nullable', 'in:new,existing'],
            'td_funding_bank_account_id'   => ['nullable', 'exists:bank_accounts,id', 'required_if:td_entry_type,new'],
            'td_start_date'                => ['nullable', 'date'],
            'td_maturity_date'             => ['nullable', 'date', 'after:td_start_date'],
            'td_interest_rate'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'td_wht_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'], // M-33
            'td_amount'                    => ['nullable', 'numeric', 'min:0'],
            'is_active'                    => ['boolean'],
        ];
    }
}
