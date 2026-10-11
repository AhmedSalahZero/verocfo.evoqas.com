<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000231_create_employee_salary_revisions_table
//
//  Append-only history of an Employee Payroll Profile's base salary
//  over time — the follow-up flagged in migration 000226's own
//  header ("No effective-dating/history table yet ... base_salary
//  changes overwrite in place ... flagged as a likely follow-up
//  once real usage shows whether a raise history matters
//  operationally"). Confirmed with Mahmoud Sept 2026: every salary
//  change, even one effective today, now goes through this table —
//  there is no other way to change base_salary. See
//  EmployeePayrollProfileService::addSalaryRevision().
//
//  effective_date is the date this amount starts applying, entered
//  by the user (e.g. "50,000 starting 1 Jul 2026"), NOT the date the
//  row was created — a raise can be scheduled ahead of time.
//
//  PayrollRunService::create() picks the correct salary for a given
//  Payroll Run by finding the latest revision whose effective_date
//  falls on or before the LAST day of that run's period — see
//  EmployeePayrollProfile::salaryAsOfDate(). A run for July uses
//  whichever revision is newest as of July 31; a revision dated
//  1 Aug does not apply until the August run.
//
//  employee_payroll_profiles.base_salary is kept as a denormalized
//  "current as of today" mirror, recalculated from this table every
//  time a revision is added (see addSalaryRevision()) — so every
//  existing read of profile.base_salary (the Employees list, etc.)
//  keeps working unchanged. This table is the actual source of
//  truth; that column is a cache of it.
//
//  @layer    Migration
//  @depends  employee_payroll_profiles, companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_revisions', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Profile this revision belongs to ──────────────────
            $table->foreignId('employee_payroll_profile_id')
                  ->constrained('employee_payroll_profiles')
                  ->cascadeOnDelete();

            // ── The change ────────────────────────────────────────
            $table->decimal('base_salary', 15, 2);
            $table->date('effective_date');
            $table->string('note')->nullable();

            // ── Who ───────────────────────────────────────────────
            $table->foreignId('created_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            // ── Timestamp ─────────────────────────────────────────
            // Append-only — no updated_at needed, same posture as
            // partner_employee_history.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'employee_payroll_profile_id'], 'esr_company_profile_idx');
            $table->index(['company_id', 'employee_payroll_profile_id', 'effective_date'], 'esr_company_profile_date_idx');

            // One revision per profile per date — two different
            // amounts both "effective" on the same day is ambiguous
            // and almost certainly a data-entry mistake.
            $table->unique(['employee_payroll_profile_id', 'effective_date'], 'esr_profile_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_revisions');
    }
};