<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000257_alter_employee_payroll_profiles_add_manufacturing_role
//
//  Employee Manufacturing Role (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 2). Adds the Direct Labor / Manufacturing
//  Overhead split within the "manufacturing" expense category, and —
//  when Overhead — which specific Overhead Pool the salary belongs
//  to.
//
//  Both columns are the same kind of "current as of today" mirror
//  base_salary and expense_category already are — the real source of
//  truth is the dated history table (employee_manufacturing_role_
//  revisions, migration 000258), recalculated every time a revision
//  is added. See EmployeePayrollProfile::manufacturingRoleAsOfDate()
//  and EmployeePayrollProfileService::addManufacturingRoleRevision().
//
//  Both nullable — only meaningful once expense_category has ever
//  been "manufacturing," and even then a profile may not have set
//  this yet (e.g. one created before this feature existed).
//
//  overhead_pool_id only ever has a value when manufacturing_role is
//  'overhead' — enforced in the service layer, not a DB constraint,
//  same pattern as every other conditional-field rule in this app.
//  cascadeOnDelete matches the existing convention for overhead_pool_id
//  foreign keys elsewhere (see standard_rates, migration 000088).
//
//  @layer    Migration
//  @depends  employee_payroll_profiles, overhead_pools
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->enum('manufacturing_role', ['direct_labor', 'overhead'])->nullable()->after('expense_category');
            $table->foreignId('overhead_pool_id')->nullable()->after('manufacturing_role')
                  ->constrained('overhead_pools')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropForeign(['overhead_pool_id']);
            $table->dropColumn(['manufacturing_role', 'overhead_pool_id']);
        });
    }
};
