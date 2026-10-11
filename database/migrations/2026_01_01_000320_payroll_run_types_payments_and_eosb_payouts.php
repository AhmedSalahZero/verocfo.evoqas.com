<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000320_payroll_run_types_payments_and_eosb_payouts   (audit M-36)
//
//  1. payroll_runs.run_type / sequence / label — a month may now have
//     extra runs (bonus, final settlement) besides the one regular run.
//     The old "one run per month" unique key becomes "one run per
//     month per type and sequence" (new key added BEFORE the old one
//     is dropped, because the company foreign key needs an index).
//  2. payroll_run_payments — the net-pay payment of a posted run
//     (Dr 239 Salaries Payable / Cr Bank), linked to the run.
//  3. eosb_payouts — paying out an employee's end-of-service benefit
//     (releases the provision; any shortfall is expensed as a true-up).
//
//  @layer    Migration
//  @depends  payroll_runs, bank_accounts, journal_entries, partners, employee_payroll_profiles
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->enum('run_type', ['regular', 'bonus', 'final_settlement'])->default('regular')->after('period_month');
            $table->unsignedTinyInteger('sequence')->default(1)->after('run_type');
            $table->string('label', 120)->nullable()->after('sequence');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->unique(['company_id', 'period_year', 'period_month', 'run_type', 'sequence'], 'pr_company_period_type_unique');
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique('pr_company_period_unique');
        });

        Schema::create('payroll_run_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'payroll_run_id']);
        });

        Schema::create('eosb_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('employee_payroll_profile_id')->constrained('employee_payroll_profiles');
            $table->date('payout_date');
            $table->decimal('payable_amount', 15, 2);        // what the employee is owed (functional)
            $table->decimal('provision_released', 15, 2);    // taken from provision 241
            $table->decimal('true_up_expense', 15, 2)->default(0); // shortfall expensed now
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eosb_payouts');
        Schema::dropIfExists('payroll_run_payments');
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->unique(['company_id', 'period_year', 'period_month'], 'pr_company_period_unique');
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique('pr_company_period_type_unique');
            $table->dropColumn(['run_type', 'sequence', 'label']);
        });
    }
};
