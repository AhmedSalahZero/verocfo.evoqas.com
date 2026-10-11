<?php

namespace App\Rules;

// ══════════════════════════════════════════════════════════════════
//  ExistsForCompany
//
//  Validation rule: "the chosen record exists AND belongs to the
//  company this request is working in".
//
//  Why it exists (Audit M1, Sept 2026):
//  Laravel's plain `exists:payment_terms,id` only checks that the id
//  exists somewhere in the table — so a user could post the id of
//  ANOTHER company's custom payment term or sales channel and the
//  form would accept it. This rule adds the company check.
//
//  $allowSystemRows = true also accepts shared system rows
//  (company_id = NULL) — used for payment terms, sales channels,
//  currencies and chart-of-accounts system accounts.
//
//  Optional $extra closure adds further conditions, e.g.
//      new ExistsForCompany('chart_of_accounts', true,
//          fn ($q) => $q->where('is_postable', true))
//
//  Company comes from TenantContext (same resolution as the
//  ActiveCompany middleware). No company → fails closed.
//
//  @layer    Rule
//  @depends  App\Support\TenantContext
// ══════════════════════════════════════════════════════════════════

use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class ExistsForCompany implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly bool $allowSystemRows = false,
        private readonly ?Closure $extra = null,
        private readonly string $column = 'id',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return; // "required" / "nullable" decide whether blank is OK.
        }

        $companyId = TenantContext::companyId();

        if ($companyId === null) {
            $fail('The selected :attribute is invalid.');
            return;
        }

        $query = DB::table($this->table)->where($this->column, $value);

        if ($this->allowSystemRows) {
            $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
        } else {
            $query->where('company_id', $companyId);
        }

        if ($this->extra) {
            ($this->extra)($query);
        }

        if (! $query->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
