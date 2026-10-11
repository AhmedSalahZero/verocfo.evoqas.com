<?php

namespace App\Models\Scopes;

// ══════════════════════════════════════════════════════════════════
//  CompanyScope
//
//  Global scope applied to all models using BelongsToCompany trait.
//  Automatically filters every query to the current company.
//
//  FIX (July 2026 audit): some tables intentionally seed rows with
//  company_id = NULL to mean "system-wide, visible to every company"
//  — currencies, chart_of_accounts, payment_terms, units_of_measure.
//  The previous version of this scope did a plain
//  `WHERE company_id = X`, which silently excludes every NULL row
//  too (NULL = X is never true in SQL). That meant any eager-loaded
//  relation pointing at a system record (e.g. a Sales Order's
//  `currency()` relation, when the currency is a seeded system
//  currency rather than a company-specific override) would come back
//  empty after a fresh() reload — a bug that had already been patched
//  one-off in several individual services instead of fixed here.
//
//  Fix: also allow company_id IS NULL through, for every model that
//  uses this scope. This is safe for every table — a table that
//  never has NULL company_id rows (Partners, Projects, Sales Orders,
//  etc.) simply never matches the extra OR clause, so behavior is
//  unchanged for them. Tables that DO use NULL for system records
//  now work correctly everywhere, without needing withoutGlobalScopes()
//  sprinkled through the codebase.
//
//  AUDIT M3 FIX (Sept 2026): the NULL pass-through above is now
//  OPT-IN. Previously EVERY tenant table let company_id IS NULL rows
//  through, so any row accidentally saved without a company (or a
//  platform-level audit-log entry written by the Super Admin) became
//  visible to every client company.
//  Now only models that genuinely hold shared system records declare
//      public const SHARES_SYSTEM_ROWS = true;
//  and only those see NULL rows. Today that is: Currency,
//  ChartOfAccount, PaymentTerm, SalesChannel. Every other tenant
//  model filters strictly on company_id = current company.
//
//  Safety guard: app('currentCompanyId') is bound by ActiveCompany
//  middleware — but route model binding resolves BEFORE middleware
//  runs. We check app()->bound() before calling app() to prevent
//  a BindingResolutionException during implicit model binding.
//
//  If currentCompanyId is not yet bound (e.g. during route model
//  binding resolution), the scope is skipped. The middleware will
//  subsequently validate company access before any response.
//
//  @layer    Scope
//  @depends  ActiveCompany middleware (binds currentCompanyId)
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Audit H9 (Sept 2026): the filter used to require a LOGGED-IN
        // user as well, so it was silently off in every job and console
        // command — even ones that had bound a company. It now applies
        // whenever a company is bound (ActiveCompany for web requests,
        // TenantContext::runAs() for jobs/commands). With no company
        // bound at all — seeders, migrations, platform-level commands —
        // behaviour is unchanged (no filter), exactly as before.
        if (app()->bound('currentCompanyId') && app('currentCompanyId')) {
            $column = $model->getTable() . '.company_id';

            // Strict tenant filter for ordinary tenant tables (M3).
            if (! self::sharesSystemRows($model)) {
                $builder->where($column, app('currentCompanyId'));
                return;
            }

            $builder->where(function ($query) use ($column) {
                $query->where($column, app('currentCompanyId'))
                      ->orWhereNull($column);
            });
        }
    }

    /**
     * True only for models that hold shared, platform-wide system rows
     * (company_id = NULL) — they declare SHARES_SYSTEM_ROWS = true.
     * Used here and by BelongsToCompany::resolveRouteBindingQuery().
     */
    public static function sharesSystemRows(Model $model): bool
    {
        $const = get_class($model) . '::SHARES_SYSTEM_ROWS';

        return defined($const) && constant($const) === true;
    }
}