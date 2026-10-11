<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeePayrollProfileService
//
//  CRUD for employee salary setup — one profile per Employee-type
//  Partner. See EmployeePayrollProfile / migration 000226 headers
//  for why this is its own table, not columns on `partners`.
//
//  create() enforces one profile per partner (the table's own unique
//  constraint would catch this too, but a DomainException here reads
//  far better than a raw DB constraint violation reaching the user).
//
//  base_salary is no longer editable directly (Sept 2026) — every
//  change, including the very first one at profile creation, goes
//  through an EmployeeSalaryRevision row. See addSalaryRevision()
//  and migration 000231's header.
//
//  expense_category is no longer editable directly either (Sept
//  2026 — Manufacturing Costing Redesign, Section 1, same fix as
//  base_salary above) — every change, including the starting
//  category at profile creation, goes through an
//  EmployeeClassificationRevision row. See
//  addClassificationRevision() and migration 000256's header.
//
//  manufacturing_role / overhead_pool_id (Sept 2026 — Manufacturing
//  Costing Redesign, Section 2) follow the exact same pattern —
//  addManufacturingRoleRevision() is the only way to set or change
//  them, and create() seeds a starting revision when the employee's
//  starting category is 'manufacturing'. Only meaningful for
//  manufacturing employees; left untouched (both null) otherwise.
//
//  @layer    Service
//  @depends  EmployeePayrollProfile, EmployeeSalaryRevision, EmployeeClassificationRevision, EmployeeManufacturingRoleRevision, Partner, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\EmployeeClassificationRevision;
use App\Models\EmployeeManufacturingRoleRevision;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeSalaryRevision;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EmployeePayrollProfileService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return EmployeePayrollProfile::with('partner:id,name_en,name_ar,code')
            ->where('company_id', $companyId)
            ->when($filters['expense_category'] ?? null, fn ($q, $v) => $q->where('expense_category', $v))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): EmployeePayrollProfile
    {
        $partner = Partner::where('company_id', $companyId)->findOrFail($data['partner_id']);

        if (! in_array(Partner::TYPE_EMPLOYEE, $partner->types ?? [], true)) {
            throw new \DomainException('A payroll profile can only be created for a partner tagged as Employee.');
        }

        $exists = EmployeePayrollProfile::where('company_id', $companyId)
            ->where('partner_id', $partner->id)->exists();

        if ($exists) {
            throw new \DomainException('This employee already has a payroll profile — edit it instead of creating a new one.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $profile = EmployeePayrollProfile::create([
                'company_id'         => $companyId,
                'partner_id'         => $data['partner_id'],
                'base_salary'        => $data['base_salary'],
                'currency_id'        => $data['currency_id'],
                'expense_category'   => $data['expense_category'],
                'eosb_rate_percent'  => $data['eosb_rate_percent'] ?? null,
                'date_of_birth'      => $data['date_of_birth'] ?? null,
                'is_active'          => true,
                'created_by'         => $actor->id,
            ]);

            // HR-002 fix (8 Sep 2026) — was hardcoded to now(), so a
            // company migrating an existing employee onto the system
            // could never record their real hire date, and every
            // payroll run for a period before "today" (the day the
            // profile happened to be entered) priced against the
            // wrong figure. Now uses the real starting date when
            // given, falling back to today for a genuinely new hire —
            // same behavior as before whenever this is omitted.
            $startDate = $data['effective_date'] ?? now()->toDateString();

            // Seed the first salary revision — effective on the real
            // starting date, since this is the employee's starting
            // salary, not a scheduled change. Every base_salary figure
            // from here on is a row in this table; the column above
            // is just its mirror.
            EmployeeSalaryRevision::create([
                'company_id'                    => $companyId,
                'employee_payroll_profile_id'   => $profile->id,
                'base_salary'                   => $data['base_salary'],
                'effective_date'                => $startDate,
                'note'                          => 'Starting salary',
                'created_by'                    => $actor->id,
            ]);

            // Seed the first classification revision — same reasoning
            // as the salary revision above: this is the employee's
            // starting category, not a scheduled change.
            EmployeeClassificationRevision::create([
                'company_id'                    => $companyId,
                'employee_payroll_profile_id'   => $profile->id,
                'expense_category'              => $data['expense_category'],
                'effective_date'                => $startDate,
                'note'                          => 'Starting classification',
                'created_by'                    => $actor->id,
            ]);

            // Seed the first Manufacturing Role revision — only when
            // the employee starts out as Manufacturing and a role was
            // actually chosen. A non-manufacturing employee, or one
            // created before choosing a role, simply has no role
            // history yet; both mirror columns stay null until the
            // first addManufacturingRoleRevision() call.
            if ($data['expense_category'] === EmployeePayrollProfile::CATEGORY_MANUFACTURING && ! empty($data['manufacturing_role'])) {
                $this->assertValidRoleAndPool($data['manufacturing_role'], $data['overhead_pool_id'] ?? null);

                EmployeeManufacturingRoleRevision::create([
                    'company_id'                    => $companyId,
                    'employee_payroll_profile_id'   => $profile->id,
                    'role'                          => $data['manufacturing_role'],
                    'overhead_pool_id'              => $data['overhead_pool_id'] ?? null,
                    'effective_date'                => $startDate,
                    'note'                          => 'Starting role',
                    'created_by'                    => $actor->id,
                ]);

                $profile->update([
                    'manufacturing_role' => $data['manufacturing_role'],
                    'overhead_pool_id'   => $data['overhead_pool_id'] ?? null,
                ]);
            }

            AuditLog::record($actor, 'employee_payroll_profile.created', $profile, null, $profile->toArray());

            return $profile->fresh(['partner', 'currency']);
        });
    }

    public function update(EmployeePayrollProfile $profile, array $data, User $actor): EmployeePayrollProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            $before = $profile->toArray();

            // base_salary and expense_category deliberately excluded —
            // see addSalaryRevision() / addClassificationRevision().
            $profile->update([
                'currency_id'        => $data['currency_id'],
                'eosb_rate_percent'  => $data['eosb_rate_percent'] ?? null,
                'date_of_birth'      => $data['date_of_birth'] ?? $profile->date_of_birth,
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'employee_payroll_profile.updated', $profile, $before, $profile->fresh()->toArray());

            return $profile->fresh(['partner', 'currency']);
        });
    }

    /**
     * M-34: moves the employee's STARTING salary / classification / role
     * to an earlier real hire date — for staff migrated onto the system
     * with today's date, so payroll for earlier months prices against
     * the right (starting) figures. Only ever moves the first revision
     * earlier; later raises are untouched.
     */
    public function backdateStartDate(EmployeePayrollProfile $profile, string $newDate, User $actor): void
    {
        $firstSalary = $profile->salaryRevisions()->orderBy('effective_date')->orderBy('id')->first();
        if (! $firstSalary) {
            throw new \DomainException('This employee has no salary history to back-date.');
        }
        if ($newDate >= substr((string) $firstSalary->effective_date, 0, 10)) {
            throw new \DomainException('The new start date must be EARLIER than the current start date ('.substr((string) $firstSalary->effective_date, 0, 10).').');
        }

        DB::transaction(function () use ($profile, $newDate, $firstSalary, $actor) {
            $before = ['start' => substr((string) $firstSalary->effective_date, 0, 10)];

            $firstSalary->update(['effective_date' => $newDate]);

            foreach ([$profile->classificationRevisions(), $profile->manufacturingRoleRevisions()] as $rel) {
                $first = $rel->orderBy('effective_date')->orderBy('id')->first();
                if ($first && substr((string) $first->effective_date, 0, 10) > $newDate) {
                    $first->update(['effective_date' => $newDate]);
                }
            }

            AuditLog::record($actor, 'employee_payroll.start_backdated', $profile, $before, ['start' => $newDate]);
        });
    }

    /**
     * Records a new salary — an amount plus the date it starts
     * applying. Can be dated in the past (a correction), today (an
     * immediate change), or the future (a scheduled raise) — Payroll
     * Run creation always resolves the right one per period via
     * EmployeePayrollProfile::salaryAsOfDate().
     *
     * After inserting, the profile's base_salary mirror column is
     * recalculated from the full history as of today — not just set
     * to this new amount — so adding a past-dated correction doesn't
     * wrongly override an already-active later revision.
     */
    public function addSalaryRevision(EmployeePayrollProfile $profile, array $data, User $actor): EmployeeSalaryRevision
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            $before = $profile->toArray();

            $revision = EmployeeSalaryRevision::create([
                'company_id'                    => $profile->company_id,
                'employee_payroll_profile_id'   => $profile->id,
                'base_salary'                   => $data['base_salary'],
                'effective_date'                => $data['effective_date'],
                'note'                           => $data['note'] ?? null,
                'created_by'                     => $actor->id,
            ]);

            $profile->update([
                'base_salary' => $profile->salaryAsOfDate(now()->toDateString()),
                'updated_by'  => $actor->id,
            ]);

            AuditLog::record($actor, 'employee_payroll_profile.salary_revised', $profile, $before, $profile->fresh()->toArray());

            return $revision;
        });
    }

    /**
     * Records a new classification — a category plus the date it
     * starts applying. Can be dated in the past (a correction), today
     * (an immediate change), or the future (a scheduled change, e.g.
     * "becomes Production Manager from 1 Jan") — Payroll Run creation
     * always resolves the right one per period via
     * EmployeePayrollProfile::classificationAsOfDate(). Mirrors
     * addSalaryRevision() exactly — same reasoning, same mirror-column
     * refresh pattern.
     */
    public function addClassificationRevision(EmployeePayrollProfile $profile, array $data, User $actor): EmployeeClassificationRevision
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            $before = $profile->toArray();

            $revision = EmployeeClassificationRevision::create([
                'company_id'                    => $profile->company_id,
                'employee_payroll_profile_id'   => $profile->id,
                'expense_category'              => $data['expense_category'],
                'effective_date'                => $data['effective_date'],
                'note'                           => $data['note'] ?? null,
                'created_by'                     => $actor->id,
            ]);

            $profile->update([
                'expense_category' => $profile->classificationAsOfDate(now()->toDateString()),
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'employee_payroll_profile.classification_revised', $profile, $before, $profile->fresh()->toArray());

            return $revision;
        });
    }

    /**
     * Records a new Manufacturing Role — Direct Labor or Overhead
     * (plus, when Overhead, which Overhead Pool) — and the date it
     * starts applying. Mirrors addClassificationRevision() exactly.
     * Confirmed with Mahmoud: this is how a Direct Labor employee
     * promoted to Production Manager gets reclassified without ever
     * rewriting their past cost history — the new role only applies
     * from its own effective date forward.
     */
    public function addManufacturingRoleRevision(EmployeePayrollProfile $profile, array $data, User $actor): EmployeeManufacturingRoleRevision
    {
        $this->assertValidRoleAndPool($data['role'], $data['overhead_pool_id'] ?? null);

        return DB::transaction(function () use ($profile, $data, $actor) {
            $before = $profile->toArray();

            $revision = EmployeeManufacturingRoleRevision::create([
                'company_id'                    => $profile->company_id,
                'employee_payroll_profile_id'   => $profile->id,
                'role'                          => $data['role'],
                'overhead_pool_id'              => $data['overhead_pool_id'] ?? null,
                'effective_date'                => $data['effective_date'],
                'note'                           => $data['note'] ?? null,
                'created_by'                     => $actor->id,
            ]);

            $resolved = $profile->manufacturingRoleAsOfDate(now()->toDateString());

            $profile->update([
                'manufacturing_role' => $resolved['role'],
                'overhead_pool_id'   => $resolved['overhead_pool_id'],
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'employee_payroll_profile.role_revised', $profile, $before, $profile->fresh()->toArray());

            return $revision;
        });
    }

    public function toggleActive(EmployeePayrollProfile $profile, User $actor): EmployeePayrollProfile
    {
        $before = $profile->toArray();
        $profile->update(['is_active' => ! $profile->is_active, 'updated_by' => $actor->id]);
        AuditLog::record($actor, 'employee_payroll_profile.toggled', $profile, $before, $profile->fresh()->toArray());

        return $profile->fresh();
    }

    // ── Internal ──────────────────────────────────────────────────

    // An Overhead Pool only makes sense paired with the Overhead role,
    // and a Direct Labor role must never carry one — enforced here as
    // the source of truth, in addition to the same rule in
    // EmployeeManufacturingRoleRevisionRequest, since a raw
    // ::create() call would otherwise bypass request validation
    // entirely.
    private function assertValidRoleAndPool(string $role, ?int $overheadPoolId): void
    {
        if ($role === EmployeePayrollProfile::ROLE_OVERHEAD && ! $overheadPoolId) {
            throw new \DomainException('An Overhead Pool is required when the role is Manufacturing Overhead.');
        }

        if ($role === EmployeePayrollProfile::ROLE_DIRECT_LABOR && $overheadPoolId) {
            throw new \DomainException('Direct Labor cannot be assigned to an Overhead Pool.');
        }
    }
}
