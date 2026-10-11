<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000244_create_plan_manpower_titles_table
//
//  One row per JOB TITLE in a plan's Manpower step — confirmed
//  design (Sept 2026): title-level planning, never named individual
//  employees. Two ways a row gets here:
//
//    is_existing = true  → seeded automatically from the company's
//      real EmployeePayrollProfile rows, grouped by Partner.job_title
//      (PlanManpowerService::snapshotExistingTeam()). existing_headcount
//      and avg_current_salary are a SNAPSHOT taken at the moment the
//      plan's Manpower step is built — frozen into this row, not a
//      live read — so a plan's numbers don't silently drift if the
//      real team changes after the plan was built.
//    is_existing = false → a title the user typed in fresh that
//      doesn't exist in the real team yet (a brand-new role the plan
//      assumes will be created). existing_headcount is always 0 for
//      these; avg_current_salary is the user's own estimate.
//
//  expense_category mirrors EmployeePayrollProfile::CATEGORY_* exactly
//  (sales/marketing/general_admin/cost_of_service/manufacturing) so
//  the calculation engine can post planned payroll cost to the same
//  P&L category the real payroll would use (521/531/541/513 —
//  spec Section 3.2's confirmed COA mapping).
//
//  Planned new hiring per year lives in plan_manpower_hiring_lines
//  (one row per title per plan year), not on this row — a title can
//  be hired into gradually across several years of a Business Plan.
//
//  @layer    Migration
//  @depends  plans
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_manpower_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            $table->string('job_title', 150);
            $table->enum('expense_category', [
                'sales', 'marketing', 'general_admin', 'cost_of_service', 'manufacturing',
            ]);

            $table->boolean('is_existing')->default(false);
            $table->unsignedSmallInteger('existing_headcount')->default(0);
            $table->decimal('avg_current_salary', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['plan_id', 'job_title']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_manpower_titles');
    }
};
