<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000256_create_employee_classification_revisions_table
//
//  Employee classification history fix (VeroCFO_Manufacturing_
//  Costing_Redesign_v1_0.md, Section 1). Deliberately the same shape
//  as employee_salary_revisions (migration 000231) — same problem,
//  same fix: base_salary already had a dated history table and
//  PayrollRunService already resolved "what applied during this
//  period" correctly; expense_category did not — changing it
//  overwrote the field directly with no effective date, and a
//  Payroll Run read whatever the category is TODAY, not what applied
//  during the period being run. Confirmed with Mahmoud Sept 2026:
//  every classification change, even one effective today, now goes
//  through this table — see
//  EmployeePayrollProfileService::addClassificationRevision().
//
//  effective_date is the date this category starts applying, entered
//  by the user, NOT the date the row was created — a reclassification
//  can be scheduled ahead of time, same as a salary raise.
//
//  PayrollRunService picks the correct category for a given Payroll
//  Run the same way it already picks the correct salary: the latest
//  revision whose effective_date falls on or before the LAST day of
//  that run's period — see
//  EmployeePayrollProfile::classificationAsOfDate().
//
//  employee_payroll_profiles.expense_category is kept as a
//  denormalized "current as of today" mirror, recalculated from this
//  table every time a revision is added (see
//  addClassificationRevision()) — so every existing read of
//  profile.expense_category (the Employees list, filters, etc.) keeps
//  working unchanged. This table is the actual source of truth; that
//  column is a cache of it — identical relationship to base_salary /
//  employee_salary_revisions.
//
//  This table also carries the future "Manufacturing Role" field
//  (Direct Labor vs. Manufacturing Overhead — Section 2 of the design
//  doc) once that's built, on the same dated-history mechanism rather
//  than repeating this same gap a second time.
//
//  @layer    Migration
//  @depends  employee_payroll_profiles, companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_classification_revisions', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Profile this revision belongs to ──────────────────
            // Named explicitly ('ecr_profile_fk') — the auto-generated
            // name (table + column, both long) exceeds MySQL's 64-char
            // identifier limit. Same fix already used elsewhere in
            // this codebase (see facility_contract_assignments).
            $table->foreignId('employee_payroll_profile_id');
            $table->foreign('employee_payroll_profile_id', 'ecr_profile_fk')
                  ->references('id')->on('employee_payroll_profiles')
                  ->cascadeOnDelete();

            // ── The change ────────────────────────────────────────
            // Same enum values as employee_payroll_profiles.expense_category
            // (migration 000226 + 000230) — kept in sync manually since
            // MySQL enums can't be shared across tables.
            $table->enum('expense_category', ['manufacturing', 'sales', 'marketing', 'general_admin', 'cost_of_service']);
            $table->date('effective_date');
            $table->string('note')->nullable();

            // ── Who ───────────────────────────────────────────────
            $table->foreignId('created_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            // ── Timestamp ─────────────────────────────────────────
            // Append-only — no updated_at needed, same posture as
            // employee_salary_revisions / partner_employee_history.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'employee_payroll_profile_id'], 'ecr_company_profile_idx');
            $table->index(['company_id', 'employee_payroll_profile_id', 'effective_date'], 'ecr_company_profile_date_idx');

            // One revision per profile per date — two different
            // categories both "effective" on the same day is
            // ambiguous and almost certainly a data-entry mistake.
            $table->unique(['employee_payroll_profile_id', 'effective_date'], 'ecr_profile_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_classification_revisions');
    }
};
