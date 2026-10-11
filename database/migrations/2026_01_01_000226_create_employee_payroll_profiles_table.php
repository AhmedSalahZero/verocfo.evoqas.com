<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000226_create_employee_payroll_profiles_table
//
//  One row per Employee-type Partner, holding everything Payroll
//  needs to run — deliberately NOT added as columns on `partners`
//  itself. Confirmed by migration 000020's own header: daily_rate/
//  hourly_rate were explicitly REMOVED from partners with the
//  comment "belongs to HR module" — the same reasoning applies here,
//  even more so now that Payroll is a real module. Partner stays a
//  lean multi-role master record (a row can be Customer + Supplier +
//  Employee simultaneously); salary data lives in its own domain.
//
//  expense_category drives which GL accounts a salary/reimbursement
//  posts to (confirmed with Mahmoud):
//    manufacturing  → no direct salary expense account at all — labor
//                     is capitalized into WIP via 237 Direct Labor
//                     Clearing at recordLaborCost() time; Payroll
//                     clears 237 rather than debiting a fresh expense
//                     (see PayrollRunService header once built).
//    sales / marketing / general_admin → debits 521/531/541
//                     (salary) and 522/532/543 (reimbursements)
//                     respectively.
//
//  eosb_rate_percent is nullable — null/0 means this employee isn't
//  enrolled in the monthly End of Service Benefits provision.
//
//  No effective-dating/history table yet (unlike PartnerEmployeeHistory
//  for department/job_title) — base_salary changes overwrite in
//  place for this first slice. Flagged as a likely follow-up once
//  real usage shows whether a raise history matters operationally.
//
//  @layer    Migration
//  @depends  partners, currencies, cost_centers, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();

            $table->decimal('base_salary', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');

            $table->enum('expense_category', ['manufacturing', 'sales', 'marketing', 'general_admin']);

            // Nullable — an EOSB rate isn't universal (probation
            // periods, contract types that don't carry EOSB, etc).
            $table->decimal('eosb_rate_percent', 5, 2)->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One active payroll profile per employee — same
            // "one row, updated in place" posture as most master
            // data in this app.
            $table->unique(['company_id', 'partner_id'], 'epp_company_partner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_profiles');
    }
};
