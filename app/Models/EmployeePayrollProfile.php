<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EmployeePayrollProfile
//
//  Salary setup for one Employee-type Partner — deliberately its own
//  table, not columns on `partners`. See migration 000226 header for
//  the full reasoning (partners' own history: daily_rate/hourly_rate
//  were explicitly removed with the comment "belongs to HR module").
//
//  expense_category is the field PayrollRunService reads to decide
//  which GL accounts a salary/reimbursement posts to — see that
//  service's header once built, and migration 000226's own note on
//  why 'manufacturing' never gets a direct salary expense account.
//
//  'cost_of_service' (added Sept 2026) is the Service /
//  Service-with-Inventory equivalent of 'manufacturing' — but unlike
//  manufacturing, it posts a direct salary expense straight to 513
//  (COGS — Services), the same way sales/marketing/general_admin
//  post to their own accounts. No Job-Order clearing mechanism —
//  confirmed decision, not a placeholder.
//
//  expense_category changes (added Sept 2026 — Manufacturing Costing
//  Redesign, Section 1): same fix as base_salary below. This column
//  is now a denormalized "current as of today" mirror, not the
//  source of truth — see EmployeeClassificationRevision / migration
//  000256. Recalculated by
//  EmployeePayrollProfileService::addClassificationRevision() every
//  time a revision is added. Never write to it directly outside that
//  method.
//
//  manufacturing_role / overhead_pool_id (added Sept 2026 —
//  Manufacturing Costing Redesign, Section 2): the Direct Labor vs.
//  Manufacturing Overhead split, only meaningful when
//  expense_category is 'manufacturing'. Same "current as of today"
//  mirror pattern — see EmployeeManufacturingRoleRevision / migration
//  000258. Recalculated by
//  EmployeePayrollProfileService::addManufacturingRoleRevision().
//  Confirmed with Mahmoud: someone can move from Direct Labor to
//  Manufacturing Overhead (e.g. promoted to Production Manager)
//  without ever rewriting how their historical cost was already
//  classified.
//
//  base_salary changes (added Sept 2026): this column is now a
//  denormalized "current as of today" mirror, not the source of
//  truth — see EmployeeSalaryRevision / migration 000231. It is
//  recalculated by EmployeePayrollProfileService::addSalaryRevision()
//  every time a revision is added, so every existing read of this
//  column keeps working. Never write to it directly outside that
//  method, or the mirror and the real history will disagree.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, Currency, User, EmployeeSalaryRevision, EmployeeClassificationRevision, EmployeeManufacturingRoleRevision, OverheadPool
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayrollProfile extends Model
{
    use BelongsToCompany;

    const CATEGORY_MANUFACTURING   = 'manufacturing';
    const CATEGORY_SALES           = 'sales';
    const CATEGORY_MARKETING       = 'marketing';
    const CATEGORY_GENERAL_ADMIN   = 'general_admin';
    const CATEGORY_COST_OF_SERVICE = 'cost_of_service';

    const ALL_CATEGORIES = [
        self::CATEGORY_MANUFACTURING,
        self::CATEGORY_SALES,
        self::CATEGORY_MARKETING,
        self::CATEGORY_GENERAL_ADMIN,
        self::CATEGORY_COST_OF_SERVICE,
    ];

    // Manufacturing Role (added Sept 2026 — Manufacturing Costing
    // Redesign, Section 2) — only meaningful when expense_category is
    // 'manufacturing'. See EmployeeManufacturingRoleRevision /
    // migration 000258.
    const ROLE_DIRECT_LABOR = 'direct_labor';
    const ROLE_OVERHEAD     = 'overhead';

    // Salary expense account per category — 'manufacturing'
    // deliberately has no entry here; see class header.
    // 'cost_of_service' posts directly like sales/marketing/general_admin
    // (decision confirmed Sept 2026 — no Job-Order clearing mechanism,
    // unlike manufacturing's Direct Labor Clearing).
    const SALARY_ACCOUNT_CODE = [
        self::CATEGORY_SALES           => '521',
        self::CATEGORY_MARKETING       => '531',
        self::CATEGORY_GENERAL_ADMIN   => '541',
        self::CATEGORY_COST_OF_SERVICE => '513',
    ];

    // Employee Reimbursement account per category — same rule:
    // 'manufacturing' has no entry; reimbursements for a
    // manufacturing-floor employee are a G&A-style cost by default
    // when they occur (rare in practice), never routed through WIP.
    // 'cost_of_service' also has no entry yet — no dedicated
    // reimbursement account exists under COGS — Services (513) in the
    // Chart of Accounts, and REIMBURSEMENT_ACCOUNT_CODE is not yet
    // wired into PayrollRunService (additions/reimbursements currently
    // fold into the same salary account's gross pay posting).
    const REIMBURSEMENT_ACCOUNT_CODE = [
        self::CATEGORY_SALES         => '522',
        self::CATEGORY_MARKETING     => '532',
        self::CATEGORY_GENERAL_ADMIN => '543',
    ];

    protected $fillable = [
        'company_id', 'partner_id', 'base_salary', 'currency_id',
        'expense_category', 'manufacturing_role', 'overhead_pool_id',
        'eosb_rate_percent', 'is_active', 'date_of_birth',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'base_salary'       => 'decimal:2',
        'eosb_rate_percent' => 'decimal:2',
        'is_active'         => 'boolean',
        'date_of_birth'     => 'date:Y-m-d',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Full salary history, most recent effective_date first. */
    public function salaryRevisions(): HasMany
    {
        return $this->hasMany(EmployeeSalaryRevision::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    /** Full classification history, most recent effective_date first. */
    public function classificationRevisions(): HasMany
    {
        return $this->hasMany(EmployeeClassificationRevision::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    /** Full Manufacturing Role history, most recent effective_date first. */
    public function manufacturingRoleRevisions(): HasMany
    {
        return $this->hasMany(EmployeeManufacturingRoleRevision::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    /**
     * The base_salary that applies on a given calendar date — the
     * latest revision whose effective_date is on or before $date.
     * Falls back to the current base_salary column if this profile
     * has no revisions yet (e.g. created before this feature existed).
     *
     * PayrollRunService calls this with the LAST day of the run's
     * period, so a mid-month raise still applies to that whole
     * period's run. addSalaryRevision() calls it with today's date
     * to refresh the base_salary mirror column.
     */
    public function salaryAsOfDate(string $date): string
    {
        $amount = $this->salaryRevisions()
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('base_salary');

        if ($amount !== null) {
            return (string) $amount;
        }

        // M-34: a date BEFORE the first recorded revision (back-period
        // payroll for someone entered later, e.g. at migration). The
        // employee's STARTING salary is the right figure for it — never
        // today's salary, which may include raises made since.
        $earliest = $this->salaryRevisions()->orderBy('effective_date')->orderBy('id')->value('base_salary');

        return $earliest !== null ? (string) $earliest : (string) $this->base_salary;
    }

    /**
     * The expense_category that applies on a given calendar date —
     * the latest revision whose effective_date is on or before $date.
     * Falls back to the current expense_category column if this
     * profile has no revisions yet (e.g. created before this feature
     * existed). Mirrors salaryAsOfDate() exactly — same reasoning,
     * same fallback behavior.
     *
     * PayrollRunService calls this with the LAST day of the run's
     * period, so a mid-month reclassification still applies to that
     * whole period's run.
     * addClassificationRevision() calls it with today's date to
     * refresh the expense_category mirror column.
     */
    public function classificationAsOfDate(string $date): string
    {
        $category = $this->classificationRevisions()
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('expense_category');

        if ($category !== null) {
            return $category;
        }

        // M-34: before the first revision → the starting category, not today's.
        $earliest = $this->classificationRevisions()->orderBy('effective_date')->orderBy('id')->value('expense_category');

        return $earliest !== null ? $earliest : $this->expense_category;
    }

    /**
     * The Manufacturing Role (and Overhead Pool, if applicable) that
     * applies on a given calendar date — the latest revision whose
     * effective_date is on or before $date. Falls back to the current
     * mirror columns if this profile has no revisions yet. Mirrors
     * salaryAsOfDate() / classificationAsOfDate() exactly, but returns
     * both paired values together since a role change and its pool
     * assignment always happen on the same revision row.
     *
     * @return array{role: ?string, overhead_pool_id: ?int}
     */
    public function manufacturingRoleAsOfDate(string $date): array
    {
        $revision = $this->manufacturingRoleRevisions()
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        if ($revision) {
            return ['role' => $revision->role, 'overhead_pool_id' => $revision->overhead_pool_id];
        }

        return ['role' => $this->manufacturing_role, 'overhead_pool_id' => $this->overhead_pool_id];
    }

    public function isDirectLabor(): bool
    {
        return $this->manufacturing_role === self::ROLE_DIRECT_LABOR;
    }

    public function isOverheadRole(): bool
    {
        return $this->manufacturing_role === self::ROLE_OVERHEAD;
    }

    public function isManufacturing(): bool
    {
        return $this->expense_category === self::CATEGORY_MANUFACTURING;
    }

    public function isEnrolledInEosb(): bool
    {
        return $this->eosb_rate_percent !== null && bccomp((string) $this->eosb_rate_percent, '0', 2) > 0;
    }
}
