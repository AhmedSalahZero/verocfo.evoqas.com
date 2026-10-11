<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  CompanyService
//
//  Handles all business logic for Company management.
//  Called exclusively by CompanyController.
//
//  Updated in v1.1:
//  — create() now calls FiscalYearService::createFirst() after
//    the company is saved. This auto-generates the first fiscal
//    year and its periods based on opening_balance_date and
//    fiscal_year_start_month (Rule 25.10).
//
//  Updated in v1.2:
//  — create() now also creates the company_active_currencies row
//    for the functional currency (base_currency). The migration
//    always intended this row to exist from day one — nothing was
//    ever creating it, so every company's own main currency was
//    silently missing from every currency dropdown that reads via
//    the pivot table.
//
//  @layer    Service
//  @depends  App\Models\Company, AuditLog
//            App\Services\FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\InventoryLocationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(
        private FiscalYearService         $fiscalYearService,
        private InventoryLocationService  $locationService,
    ) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return Company::query()
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                $q->where('name_en', 'like', "%{$v}%")
                  ->orWhere('name_ar', 'like', "%{$v}%")
            )
            ->when($filters['status'] ?? null, fn ($q, $v) =>
                $q->where('is_active', $v === 'active')
            )
            ->when($filters['tier'] ?? null, fn ($q, $v) =>
                $q->where('subscription_tier', $v)
            )
            ->withCount('users')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create ────────────────────────────────────────────────────

    /**
     * Creates a new company and automatically generates its first
     * fiscal year based on opening_balance_date and
     * fiscal_year_start_month (Rule 25.10).
     */
    public function create(array $data, User $actor): Company
    {
        return DB::transaction(function () use ($data, $actor) {
            $company = Company::create($data);

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => $actor->id,
                'action'         => 'created',
                'auditable_type' => Company::class,
                'auditable_id'   => $company->id,
                'before'         => null,
                'after'          => $company->toArray(),
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);

            // Auto-activate the functional currency (v1.2 fix).
            // The company_active_currencies migration documents that
            // is_functional is "set once at company creation" — but
            // nothing actually did that. Without this row, the
            // company's own base currency never appears in any
            // currency dropdown that reads from this pivot table,
            // because it's the pivot row — not companies.base_currency —
            // that every query actually checks.
            $functionalCurrencyId = Currency::withoutGlobalScopes()
                ->whereNull('company_id')
                ->where('code', $company->base_currency)
                ->value('id');

            if (! $functionalCurrencyId) {
                throw new \DomainException(
                    "No system currency found for code '{$company->base_currency}'. ".
                    'Cannot set the functional currency for this company.'
                );
            }

            CompanyActiveCurrency::create([
                'company_id'    => $company->id,
                'currency_id'   => $functionalCurrencyId,
                'is_functional' => true,
                'is_active'     => true,
            ]);

            // Auto-create first fiscal year (Rule 25.10)
            $this->fiscalYearService->createFirst($company);

            // Auto-create Master Warehouse location
            if ($company->hasInventory()) {
                $this->locationService->createMaster($company);
            }

            return $company;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(Company $company, array $data, User $actor): Company
    {
        return DB::transaction(function () use ($company, $data, $actor) {
            $before = $company->toArray();
            $company->update($data);

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => $actor->id,
                'action'         => 'updated',
                'auditable_type' => Company::class,
                'auditable_id'   => $company->id,
                'before'         => $before,
                'after'          => $company->fresh()->toArray(),
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);

            return $company->fresh();
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Company $company, User $actor): Company
    {
        return $this->update($company, ['is_active' => ! $company->is_active], $actor);
    }

    // ── Hard Delete ──────────────────────────────────────────────

    /**
     * Permanently deletes a company and EVERYTHING that belongs to
     * it — every transaction, every setting, and every user account
     * under it. This is irreversible; there is no soft-delete or
     * trash to recover from (neither Company nor User use
     * SoftDeletes).
     *
     * WHY NOT JUST `$company->delete()` AND LET THE DATABASE
     * CASCADE: that was the first version of this method, and it
     * broke in production. Most company-scoped tables (113 of them)
     * do have company_id set to ON DELETE CASCADE, but a number of
     * OTHER tables hold a second, unrelated foreign key pointing at
     * chart_of_accounts (journal_entry_lines.chart_of_account_id,
     * bank_accounts.coa_account_id, products.revenue_account_id,
     * and others) with no cascade rule at all — the database default
     * is RESTRICT. Since journal_entry_lines and chart_of_accounts
     * are BOTH being cascade-deleted as siblings off the same
     * company row, MySQL gives no guarantee it clears the journal
     * lines before it tries to clear the accounts they point to —
     * and when it doesn't, the RESTRICT constraint blocks the whole
     * delete. Chasing down every such cross-reference one at a time
     * is a losing game; instead this method takes full manual
     * control:
     *
     *   1. Ask the database itself (information_schema) for every
     *      table that actually has a company_id column right now —
     *      not a hardcoded list that would silently go stale as the
     *      schema grows.
     *   2. Turn foreign key checking off for the duration of the
     *      delete (this also means InnoDB's own CASCADE actions
     *      won't fire — which is fine, because we're deleting every
     *      company-scoped row ourselves anyway, in one flat pass,
     *      so there's nothing left for cascade to do).
     *   3. Delete this company's rows from every one of those tables
     *      (including `users` itself — it has a company_id column
     *      like everything else, so it needs no special-casing here).
     *   4. Delete the company row.
     *   5. Turn foreign key checking back on.
     *
     * System-level rows (chart_of_accounts, currencies, payment
     * terms, etc. with company_id = NULL) are untouched throughout —
     * every delete below is scoped to `company_id = <this company>`,
     * which never matches a NULL row.
     */
    //  Audit H8 (Sept 2026): audit_log is excluded from the delete —
    //  its rows are kept — and the trail is also archived to a JSON
    //  file (storage/app/private/audit-archive/) before anything is
    //  removed. The archive path is recorded in the hard-delete entry.
    public function destroy(Company $company, User $actor): void
    {
        DB::transaction(function () use ($company, $actor) {
            $companyId = $company->id;
            $before = $company->only(['id', 'name_en', 'name_ar', 'subscription_tier', 'company_type']);
            $userCount = User::where('company_id', $companyId)->count();

            $tables = DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('column_name', 'company_id')
                // Aliased explicitly rather than plucking 'table_name'
                // directly — MySQL's information_schema returns columns
                // as TABLE_NAME (uppercase) on some servers/configs, so
                // the stdClass property Laravel got back didn't match
                // the lowercase name pluck() was asking for. An alias we
                // define ourselves is guaranteed to come back exactly
                // as written, regardless of server casing.
                ->select(DB::raw('table_name as tbl'))
                ->pluck('tbl')
                // Audit H8: the audit trail is NEVER deleted (Egyptian law
                // requires books and their trail be kept at least 5 years).
                ->reject(fn ($table) => in_array($table, ['companies', 'audit_log'], true))
                ->values();

            // Audit H8: archive this company's audit trail to a file first,
            // with user names resolved while the users still exist. The
            // audit_log rows themselves also stay in the database.
            $archivePath = $this->archiveAuditTrail($company);

            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($tables as $table) {
                    DB::table($table)->where('company_id', $companyId)->delete();
                }
                $company->delete();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            AuditLog::create([
                'company_id'     => null,
                'user_id'        => $actor->id,
                'action'         => 'company.hard_deleted',
                'auditable_type' => Company::class,
                'auditable_id'   => $companyId,
                'before'         => array_merge($before, [
                    'deleted_user_count'  => $userCount,
                    'deleted_table_count' => $tables->count(),
                    'audit_archive_file'  => $archivePath,
                ]),
                'after'          => null,
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);
        });
    }

    /**
     * Audit H8 — writes the company's full audit trail to
     * storage/app/private/audit-archive/company-<id>-<datetime>.json
     * (outside the public folder) and returns that path. Users' names
     * and emails are copied in, since the user rows are about to go.
     */
    private function archiveAuditTrail(Company $company): string
    {
        $users = User::where('company_id', $company->id)->get(['id', 'name', 'email'])->keyBy('id');

        $rows = DB::table('audit_log')
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get()
            ->map(function ($row) use ($users) {
                $row->user_name  = $users[$row->user_id]->name  ?? null;
                $row->user_email = $users[$row->user_id]->email ?? null;
                return $row;
            });

        $path = 'audit-archive/company-' . $company->id . '-' . now()->format('Ymd-His') . '.json';

        \Illuminate\Support\Facades\Storage::disk('local')->put($path, json_encode([
            'company'     => $company->only(['id', 'name_en', 'name_ar', 'subscription_tier', 'company_type']),
            'archived_at' => now()->toIso8601String(),
            'row_count'   => $rows->count(),
            'audit_log'   => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}