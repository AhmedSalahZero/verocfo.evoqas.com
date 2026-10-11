<?php

namespace App\Rules;

// ══════════════════════════════════════════════════════════════════
//  UniqueBankAccountNumber
//
//  A real bank account number is a single physical thing at a bank —
//  it doesn't matter whether Vero CFO stores it as a Bank Account
//  record (bank_accounts) or as the account number on a credit
//  facility (credit_facilities — Clean OD, CP OD, Contract Assignment
//  OD, MTL, and any future facility type sharing that table). The
//  same number must never appear twice under the same bank for the
//  same company, no matter which of those two tables it was typed
//  into.
//
//  Laravel's built-in Rule::unique() only checks one table at a time,
//  so this rule checks both explicitly. Blank values, blank bank_id,
//  or blank company_id are skipped — matches ordinary SQL unique-
//  index behavior where NULLs never collide (see migration 000116
//  for the bank_accounts-only equivalent).
//
//  Usage — create:
//    new UniqueBankAccountNumber($companyId, $bankId, 'credit_facilities')
//
//  Usage — update (excludes the record's own row from its own table
//  only; it still checks the other table in full):
//    new UniqueBankAccountNumber($companyId, $bankId, 'credit_facilities', $facility?->id)
//
//  @layer    Rule
//  @depends  bank_accounts, credit_facilities
// ══════════════════════════════════════════════════════════════════

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueBankAccountNumber implements ValidationRule
{
    /**
     * Tables that share the "one account number per bank" universe.
     * Add any future facility table here if it ever gets its own
     * account_number column instead of living in credit_facilities.
     */
    private const TABLES = ['bank_accounts', 'credit_facilities'];

    public function __construct(
        private readonly ?int $companyId,
        private readonly ?int $bankId,
        private readonly string $currentTable,
        private readonly ?int $excludeId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || blank($this->bankId) || blank($this->companyId)) {
            return;
        }

        foreach (self::TABLES as $table) {
            $query = DB::table($table)
                ->where('company_id', $this->companyId)
                ->where('bank_id', $this->bankId)
                ->where('account_number', $value);

            if ($table === $this->currentTable && $this->excludeId) {
                $query->where('id', '!=', $this->excludeId);
            }

            if ($query->exists()) {
                $fail('This account number is already used at this bank — account numbers must be unique per bank.');
                return;
            }
        }
    }
}
