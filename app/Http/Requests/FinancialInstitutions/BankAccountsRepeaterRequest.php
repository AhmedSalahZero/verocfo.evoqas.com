<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankAccountsRepeaterRequest
//
//  Validates BankController::accountsStore() — the repeater
//  submission where multiple bank accounts can be created in one
//  transaction. Wraps BankAccountRequest::fieldRules() under an
//  'accounts.*.' prefix, exactly matching the original
//  accountsRepeaterRules() → accountRules() relationship, just
//  moved out of the controller.
//
//  Fix (July 2026 — Time Deposit Lifecycle): account_number
//  uniqueness (per company + bank) is built per-row here rather than
//  through the shared static fieldRules(), since a wildcard rule
//  ("accounts.*.account_number") has no way to see its own row's
//  sibling bank_id — each row in the repeater could in principle be
//  for a different bank. Looping the actual submitted rows lets each
//  one check uniqueness against its own bank_id specifically.
//
//  Fix (August 2026 — cross-table account number uniqueness): swapped
//  the bank_accounts-only Rule::unique() for UniqueBankAccountNumber,
//  which also checks credit_facilities (Clean OD / CP OD / Contract
//  Assignment OD / MTL). Also added a same-submission check: since
//  every row here is a brand-new record, two rows in the same
//  repeater submission with the same bank_id + account_number would
//  both pass a DB-only check (neither exists yet) — withValidator()
//  below catches that case explicitly.
//
//  @layer    Request
//  @depends  App\Rules\UniqueBankAccountNumber
// ══════════════════════════════════════════════════════════════════

use App\Rules\UniqueBankAccountNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BankAccountsRepeaterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['accounts' => ['required', 'array', 'min:1']];

        foreach (BankAccountRequest::fieldRules() as $field => $fieldRules) {
            $rules["accounts.*.{$field}"] = $fieldRules;
        }

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;

        foreach ($this->input('accounts', []) as $index => $row) {
            $rules["accounts.{$index}.account_number"][] = new UniqueBankAccountNumber(
                $companyId,
                $row['bank_id'] ?? null,
                'bank_accounts',
            );
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $seen = [];

            foreach ($this->input('accounts', []) as $index => $row) {
                $number = $row['account_number'] ?? null;
                $bankId = $row['bank_id'] ?? null;

                if (blank($number) || blank($bankId)) {
                    continue;
                }

                $key = $bankId . '|' . $number;

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "accounts.{$index}.account_number",
                        'This account number is repeated in this submission — account numbers must be unique per bank.'
                    );
                } else {
                    $seen[$key] = true;
                }
            }
        });
    }
}
