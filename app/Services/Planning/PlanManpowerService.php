<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerService
//
//  Confirmed design (Sept 2026, spec Section 3.2) — the Manpower step
//  works at JOB TITLE level, never named individuals:
//
//    snapshotExistingTeam() groups the company's real, active
//    EmployeePayrollProfile rows by Partner.job_title and creates one
//    PlanManpowerTitle row per title, with existing_headcount (count
//    of employees under that title) and avg_current_salary (average
//    of their current base_salary — EmployeePayrollProfile::
//    currentSalaryAsOf()/base_salary, whichever the real payroll
//    module considers authoritative today). This is a SNAPSHOT taken
//    once, at the moment the plan's Manpower step is built — frozen
//    into the row, not a live read, so a plan's numbers don't
//    silently drift if the real team changes after the plan exists.
//
//    addPlannedTitle() lets the user add a title that doesn't exist
//    in the real team yet (existing_headcount always 0, salary is the
//    user's own estimate).
//
//    setPlannedHiring() records how many new people the plan assumes
//    get hired into a given title in a given plan year — just a
//    count, no per-hire detail (confirmed design).
//
//  @layer    Service
//  @depends  Plan, PlanManpowerTitle, PlanManpowerHiringLine,
//            EmployeePayrollProfile, Partner, AuditLog, User
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\EmployeePayrollProfile;
use App\Models\Plan;
use App\Models\PlanManpowerHiringLine;
use App\Models\PlanManpowerTitle;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PlanManpowerService
{
    /**
     * Groups the company's real, active employees by job title and
     * creates one PlanManpowerTitle per title — headcount + average
     * current salary, snapshotted now. Safe to call more than once
     * (e.g. "refresh from real team") — existing is_existing=true
     * rows for titles no longer present are left untouched rather
     * than deleted, since a plan already in progress shouldn't lose
     * data the user may have built on top of (planned hiring lines).
     */
    public function snapshotExistingTeam(Plan $plan, User $actor): array
    {
        return DB::transaction(function () use ($plan, $actor) {
            $groups = EmployeePayrollProfile::withoutGlobalScopes()
                ->where('employee_payroll_profiles.company_id', $plan->company_id)
                // PLN-001 fix (2026-09) — qualified with its table name.
                // Both employee_payroll_profiles and partners carry an
                // is_active column, so the moment the join below is
                // added MySQL rejects the whole query with "Column
                // 'is_active' in where clause is ambiguous" — which is
                // every company that has payroll profiles at all.
                ->where('employee_payroll_profiles.is_active', true)
                ->join('partners', 'partners.id', '=', 'employee_payroll_profiles.partner_id')
                ->selectRaw('
                    COALESCE(NULLIF(partners.job_title, \'\'), \'Unassigned\') as job_title,
                    employee_payroll_profiles.expense_category,
                    COUNT(*) as headcount,
                    SUM(employee_payroll_profiles.base_salary) as salary_sum
                ')
                ->groupBy('job_title', 'employee_payroll_profiles.expense_category')
                ->get();

            $titles = [];

            foreach ($groups as $group) {
                $title = PlanManpowerTitle::updateOrCreate(
                    [
                        'plan_id'   => $plan->id,
                        'job_title' => $group->job_title,
                    ],
                    [
                        'company_id'          => $plan->company_id,
                        'expense_category'    => $group->expense_category,
                        'is_existing'         => true,
                        'existing_headcount'  => (int) $group->headcount,
                        'avg_current_salary'  => (int) $group->headcount > 0
                            ? Money::round(bcdiv(Money::normalize($group->salary_sum), (string) (int) $group->headcount, 6))
                            : '0.00',
                    ]
                );

                $titles[] = $title;
            }

            AuditLog::record(
                $actor,
                'plan.manpower.snapshot_existing_team',
                $plan,
                null,
                ['titles_count' => count($titles)]
            );

            return $titles;
        });
    }

    /**
     * A title the user is adding that doesn't exist in the real team
     * yet — existing_headcount is always 0; avg_current_salary is the
     * user's own estimate for this brand-new role.
     */
    public function addPlannedTitle(Plan $plan, array $data, User $actor): PlanManpowerTitle
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $title = PlanManpowerTitle::create([
                'company_id'          => $plan->company_id,
                'plan_id'             => $plan->id,
                'job_title'           => $data['job_title'],
                'expense_category'    => $data['expense_category'],
                'is_existing'         => false,
                'existing_headcount'  => 0,
                'avg_current_salary'  => $data['avg_current_salary'] ?? 0,
            ]);

            AuditLog::record($actor, 'plan.manpower.title_added', $title, null, $title->toArray());

            return $title;
        });
    }

    /**
     * Planned new hires for one title in one plan year — just a
     * count (confirmed design). Upserts so re-submitting the same
     * year overwrites rather than duplicates.
     */
    public function setPlannedHiring(PlanManpowerTitle $title, int $planYear, int $newHiresCount, User $actor): PlanManpowerHiringLine
    {
        return DB::transaction(function () use ($title, $planYear, $newHiresCount, $actor) {
            $line = PlanManpowerHiringLine::updateOrCreate(
                [
                    'plan_manpower_title_id' => $title->id,
                    'plan_year'              => $planYear,
                ],
                [
                    'company_id'       => $title->company_id,
                    'new_hires_count'  => $newHiresCount,
                ]
            );

            AuditLog::record($actor, 'plan.manpower.hiring_set', $line, null, $line->toArray());

            return $line;
        });
    }

    public function removeTitle(PlanManpowerTitle $title, User $actor): void
    {
        DB::transaction(function () use ($title, $actor) {
            $before = $title->toArray();
            $title->delete();

            AuditLog::record($actor, 'plan.manpower.title_removed', $title, $before, null);
        });
    }
}
