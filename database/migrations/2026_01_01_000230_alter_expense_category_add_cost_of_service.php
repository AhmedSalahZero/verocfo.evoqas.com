<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000230_alter_expense_category_add_cost_of_service
//
//  'cost_of_service' was added as a valid expense_category (Sept
//  2026 — see EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE) in
//  the model, the form request validation, and the UI — but the
//  database columns themselves were left as a native MySQL ENUM
//  restricted to the original 4 values ('manufacturing', 'sales',
//  'marketing', 'general_admin'). Saving 'cost_of_service' would
//  have been rejected (or silently coerced to '') at the database
//  layer regardless of what the app validated.
//
//  Two tables carry this ENUM: employee_payroll_profiles (the
//  profile itself) and payroll_run_lines (the per-run snapshot,
//  copied from the profile at PayrollRunService::create() time).
//
//  MySQL has no ALTER ... ADD VALUE for enums — the column has to be
//  redefined with the full value list.
//
//  @layer    Migration
//  @depends  employee_payroll_profiles, payroll_run_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE employee_payroll_profiles MODIFY expense_category ENUM('manufacturing','sales','marketing','general_admin','cost_of_service') NOT NULL");
        DB::statement("ALTER TABLE payroll_run_lines MODIFY expense_category ENUM('manufacturing','sales','marketing','general_admin','cost_of_service') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE employee_payroll_profiles MODIFY expense_category ENUM('manufacturing','sales','marketing','general_admin') NOT NULL");
        DB::statement("ALTER TABLE payroll_run_lines MODIFY expense_category ENUM('manufacturing','sales','marketing','general_admin') NOT NULL");
    }
};
