<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000235_create_eosb_provision_run_tables
//
//  EOSB Provision (2026-09) — a SEPARATE, dedicated monthly run from
//  Payroll Run (confirmed with Mahmoud — not folded into the same
//  posting). One run per company per period, one line per employee
//  enrolled in EOSB (EmployeePayrollProfile::isEnrolledInEosb() —
//  eosb_rate_percent set and > 0).
//
//  Provision is calculated on BASE SALARY ONLY (confirmed with
//  Mahmoud) — never gross pay, never that month's bonuses/
//  reimbursements/allowances. Simple formula, no regulatory rate
//  table involved (unlike tax/SI), so this IS auto-calculated, no
//  manual input needed:
//    provision_amount = base_salary × eosb_rate_percent / 100
//
//  Manufacturing employees ARE included, posting as a normal expense
//  (Dr 542 EOSB Expense) exactly like every other category —
//  confirmed with Mahmoud this is the one place Manufacturing does
//  NOT get the "no fresh expense, settle via WIP/237 instead"
//  treatment Payroll Run gives it. EOSB is a real future obligation
//  regardless of department, not a product cost.
//
//  ONE posting shape, no per-category split (542/241 are single
//  company-wide accounts, unlike 521/531/541's per-category salary
//  accounts) — see EmployeePayrollProfile's SALARY_ACCOUNT_CODE vs
//  the Chart of Accounts seeder, 542/241 have no siblings:
//    Dr 542 End of Service Benefits Expense   sum(provision_amount)
//        Cr 241 End of Service Benefits Provision   sum(provision_amount)
//
//  eosb_rate_percent snapshot: base_salary AND eosb_rate_percent are
//  both snapshotted onto the line at run-creation time, same reason
//  as Payroll Run — a mid-month raise or rate change must never
//  silently alter an already-drafted run.
//
//  @layer    Migration
//  @depends  companies, partners, employee_payroll_profiles,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eosb_provision_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('run_date');

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // One run per period per company — same reasoning as
            // Payroll Run: running the same month twice is almost
            // certainly a mistake.
            $table->unique(['company_id', 'period_year', 'period_month'], 'epr_company_period_unique');
        });

        Schema::create('eosb_provision_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('eosb_provision_run_id')->constrained()->cascadeOnDelete();

            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('employee_payroll_profile_id')->constrained('employee_payroll_profiles');

            // Snapshots at run-creation time — see class header.
            $table->decimal('base_salary', 15, 2);
            $table->decimal('eosb_rate_percent', 5, 2);
            $table->foreignId('currency_id')->constrained('currencies');

            $table->decimal('provision_amount', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['company_id', 'eosb_provision_run_id']);
            // One line per employee per run — the create step reads
            // every enrolled employee once, never twice.
            $table->unique(['eosb_provision_run_id', 'partner_id'], 'eprl_run_partner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eosb_provision_run_lines');
        Schema::dropIfExists('eosb_provision_runs');
    }
};
