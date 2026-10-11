<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000317_leave_rules_working_days_tiers_carry_forward  (audit M-35)
//
//  Leave rules that reflect the Egyptian Labour Law:
//   * leave_types.skip_non_working_days — weekly rest days and public
//     holidays inside the leave are NOT counted (annual leave).
//   * leave_types.senior_entitlement_days / senior_after_years /
//     senior_age — the higher entitlement (law: 21 days, 30 days after
//     10 years' service or at age 50).
//   * leave_types.carry_forward_max_days — unused days carried into the
//     next fiscal year, up to this cap (empty = no carry-forward).
//   * companies.weekend_days — ISO weekday numbers, default Fri+Sat.
//   * company_holidays — the company's public-holiday calendar.
//   * employee_payroll_profiles.date_of_birth — for the age-50 rule.
//
//  @layer    Migration
//  @depends  leave_types, companies, employee_payroll_profiles
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('skip_non_working_days')->default(false)->after('is_paid');
            $table->decimal('senior_entitlement_days', 6, 2)->nullable()->after('annual_entitlement_days');
            $table->unsignedTinyInteger('senior_after_years')->default(10)->after('senior_entitlement_days');
            $table->unsignedTinyInteger('senior_age')->default(50)->after('senior_after_years');
            $table->decimal('carry_forward_max_days', 6, 2)->nullable()->after('senior_age');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('weekend_days', 20)->default('5,6');
        });

        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable();
        });

        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['company_id', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
        Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->dropColumn('date_of_birth'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('weekend_days'));
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropColumn(['skip_non_working_days', 'senior_entitlement_days', 'senior_after_years', 'senior_age', 'carry_forward_max_days']));
    }
};
