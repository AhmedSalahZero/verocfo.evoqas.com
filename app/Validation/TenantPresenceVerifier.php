<?php

namespace App\Validation;

// ══════════════════════════════════════════════════════════════════
//  TenantPresenceVerifier
//
//  AUDIT H2 FIX (Sept 2026). Laravel's "exists" validation rule
//  (e.g. 'cost_center_id' => 'exists:cost_centers,id') only asked
//  "is there a row with this id?" — in ANY company. 288 rules across
//  the app worked that way, so a form could be submitted with a cost
//  centre, project, unit of measure, payment term, bank account...
//  belonging to ANOTHER company and it was stored as typed.
//
//  This class is the one place Laravel asks the database during
//  validation. While an "exists" check is running (TenantValidator
//  switches $scoping on), every lookup on a table that has a
//  company_id column is limited to:
//      company_id = <current company>   OR   company_id IS NULL
//  NULL = system rows (seeded currencies, COA, payment terms, UOMs,
//  banks) — reachable exactly as they are in every dropdown.
//  Exception: users — a NULL company_id there is the Super Admin,
//  never a valid pick in a company form, so NULL is not allowed.
//
//  Not scoped (unchanged behaviour):
//    - "unique" rules. Uniqueness rules decide their own scope (e.g.
//      a user's email must be unique across the whole platform).
//    - Requests with no company context (Super Admin screens such as
//      Companies, console commands).
//    - Tables without a company_id column (child "lines" tables);
//      their parent documents are already company-checked.
//
//  The table → has-company_id answer is cached per process, so the
//  extra cost is one schema lookup per table, once.
//
//  @layer    Validation
//  @depends  App\Support\TenantContext, TenantValidator
// ══════════════════════════════════════════════════════════════════

use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\DatabasePresenceVerifier;

class TenantPresenceVerifier extends DatabasePresenceVerifier
{
    /** >0 while an "exists" rule is being checked (nesting-safe). */
    public int $scoping = 0;

    /** @var array<string,bool> */
    private static array $hasCompanyColumn = [];

    protected function table($table)
    {
        $query = parent::table($table);

        if ($this->scoping <= 0) {
            return $query;
        }

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;

        if (! $companyId || ! $this->tableHasCompanyId($table)) {
            return $query;
        }

        $column = $table . '.company_id';

        if ($table === 'users') {
            return $query->where($column, $companyId);
        }

        return $query->where(function ($q) use ($column, $companyId) {
            $q->where($column, $companyId)->orWhereNull($column);
        });
    }

    private function tableHasCompanyId(string $table): bool
    {
        $key = ($this->connection ?? 'default') . '.' . $table;

        return self::$hasCompanyColumn[$key]
            ??= Schema::connection($this->connection)->hasColumn($table, 'company_id');
    }
}
