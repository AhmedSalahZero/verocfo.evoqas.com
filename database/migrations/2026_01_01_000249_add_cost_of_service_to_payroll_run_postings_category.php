<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000249_add_cost_of_service_to_payroll_run_postings_category
//
//  Bug fix: EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE
//  ('cost_of_service') is a real, used category — it has its own
//  SALARY_ACCOUNT_CODE ('513') and REIMBURSEMENT_ACCOUNT_CODE
//  mapping right alongside sales / marketing / general_admin — but
//  the payroll_run_postings.category ENUM (migration 000228) never
//  included it. Any company with even one cost_of_service employee
//  fails EVERY Payroll Run post with "Data truncated for column
//  'category'". Adding the missing enum value; no data migration
//  needed since no row could have ever been written with it before.
//
//  @layer    Migration
//  @depends  payroll_run_postings
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payroll_run_postings MODIFY COLUMN category ENUM('manufacturing', 'sales', 'marketing', 'general_admin', 'cost_of_service') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payroll_run_postings MODIFY COLUMN category ENUM('manufacturing', 'sales', 'marketing', 'general_admin') NOT NULL");
    }
};
