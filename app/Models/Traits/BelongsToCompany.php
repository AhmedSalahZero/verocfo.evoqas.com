<?php

namespace App\Models\Traits;

// ══════════════════════════════════════════════════════════════════
//  BelongsToCompany (Trait)
//
//  Makes a model company-owned. Used by every tenant model.
//  Does two things:
//
//  1. Adds CompanyScope — filters normal queries to the current
//     company (plus system rows where company_id IS NULL).
//
//  2. Filters records loaded from the WEB ADDRESS (Audit C1 fix,
//     Sept 2026). Route model binding runs before the ActiveCompany
//     middleware, so CompanyScope is still switched off at that
//     moment. Without this, /accounting/customer-invoices/1234/edit
//     loaded invoice 1234 even if it belonged to another company.
//
//     We override resolveRouteBindingQuery() rather than
//     resolveRouteBinding() because Laravel funnels ALL three binding
//     paths through it: normal binding, soft-deleted (withTrashed)
//     binding, and nested/child binding. One override covers all.
//
//     The company comes from TenantContext: the user's own company,
//     or — for the Super Admin — the company they have entered.
//     A record from another company → "404 Not Found" (we never say
//     "forbidden", which would confirm the record exists).
//     No company resolvable at all → nothing matches (fail closed).
//
//     System rows (company_id IS NULL — seeded currencies, COA,
//     payment terms, sales channels) stay reachable, but ONLY on
//     models that declare SHARES_SYSTEM_ROWS = true (Audit M3 fix).
//     For every other model a NULL-company row is never loadable.
//
//  @layer    Model (Trait)
//  @depends  App\Models\Scopes\CompanyScope, App\Support\TenantContext
// ══════════════════════════════════════════════════════════════════

use App\Models\Scopes\CompanyScope;
use App\Support\TenantContext;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());
    }

    /**
     * Restrict every route-model-binding lookup to the current company.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query     = parent::resolveRouteBindingQuery($query, $value, $field);
        $companyId = TenantContext::companyId();
        $column    = $this->qualifyColumn('company_id');

        if ($companyId === null) {
            // No company context → nothing may be loaded from the URL.
            return $query->whereRaw('1 = 0');
        }

        // M3: only shared-system-row models may load a NULL-company row.
        if (! CompanyScope::sharesSystemRows($this)) {
            return $query->where($column, $companyId);
        }

        return $query->where(function ($q) use ($column, $companyId) {
            $q->where($column, $companyId)->orWhereNull($column);
        });
    }
}
