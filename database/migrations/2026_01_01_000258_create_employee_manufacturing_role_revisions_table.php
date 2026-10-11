<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000258_create_employee_manufacturing_role_revisions_table
//
//  Employee Manufacturing Role history (VeroCFO_Manufacturing_
//  Costing_Redesign_v1_0.md, Section 2). Same shape and same reasoning
//  as employee_classification_revisions (migration 000256) and
//  employee_salary_revisions (migration 000231) — confirmed with
//  Mahmoud: someone can be Direct Labor today and Production Manager
//  in five years, and that change must never rewrite what applied
//  during a past, already-closed pay period.
//
//  effective_date is the date this role starts applying, entered by
//  the user, NOT the date the row was created — a scheduled
//  reclassification (e.g. "becomes Production Manager from 1 Jan")
//  can be dated ahead of time, same as a salary raise or an expense
//  category change.
//
//  employee_payroll_profiles.manufacturing_role / overhead_pool_id
//  are kept as denormalized "current as of today" mirrors,
//  recalculated from this table every time a revision is added —
//  identical relationship to base_salary / expense_category and their
//  own history tables.
//
//  Foreign key names given explicitly and kept short — the
//  auto-generated name for employee_payroll_profile_id on this table
//  exceeds MySQL's 64-char identifier limit (confirmed the hard way
//  on employee_classification_revisions, migration 000256).
//
//  @layer    Migration
//  @depends  employee_payroll_profiles, overhead_pools, companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_manufacturing_role_revisions', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Profile this revision belongs to ──────────────────
            $table->foreignId('employee_payroll_profile_id');
            $table->foreign('employee_payroll_profile_id', 'emrr_profile_fk')
                  ->references('id')->on('employee_payroll_profiles')
                  ->cascadeOnDelete();

            // ── The change ────────────────────────────────────────
            $table->enum('role', ['direct_labor', 'overhead']);

            // Only meaningful when role = 'overhead' — enforced in
            // the service layer, not here, same posture as the
            // mirror columns on employee_payroll_profiles (migration
            // 000257).
            $table->foreignId('overhead_pool_id')->nullable();
            $table->foreign('overhead_pool_id', 'emrr_pool_fk')
                  ->references('id')->on('overhead_pools')
                  ->cascadeOnDelete();

            $table->date('effective_date');
            $table->string('note')->nullable();

            // ── Who ───────────────────────────────────────────────
            $table->foreignId('created_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            // ── Timestamp ─────────────────────────────────────────
            // Append-only — no updated_at needed, same posture as
            // every other revision-history table in this app.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'employee_payroll_profile_id'], 'emrr_company_profile_idx');
            $table->index(['company_id', 'employee_payroll_profile_id', 'effective_date'], 'emrr_company_profile_date_idx');

            // One revision per profile per date — same reasoning as
            // esr_profile_date_unique / ecr_profile_date_unique.
            $table->unique(['employee_payroll_profile_id', 'effective_date'], 'emrr_profile_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_manufacturing_role_revisions');
    }
};
