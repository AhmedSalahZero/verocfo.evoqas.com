<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000228_create_payroll_run_tables
//
//  The Payroll Run engine — one monthly run, one row per employee,
//  a generic addition/deduction line shape (forward-compatible with
//  future Leave Management, per Mahmoud's explicit request), and one
//  posting row per expense category actually used.
//
//  ── payroll_runs ──────────────────────────────────────────────────
//  One row per company per period (period_year/period_month —
//  deliberately not a single date, since a payroll period is a whole
//  month, not a point in time). draft while being assembled/edited;
//  posted once PayrollRunService::post() has posted every category's
//  journal entry. Same draft→posted→(set to draft) lifecycle as
//  every other financial document in this app.
//
//  ── payroll_run_lines ─────────────────────────────────────────────
//  One row per employee in this run. base_salary/currency_id/
//  expense_category are SNAPSHOTS from EmployeePayrollProfile at the
//  moment the run was created — a mid-month raise or category change
//  must never silently alter an already-drafted run.
//
//  tax_amount / social_insurance_employee_amount /
//  social_insurance_employer_amount are MANUAL INPUT for this first
//  slice — deliberately NOT auto-calculated. Egyptian income-tax
//  brackets and Social Insurance ceilings/rates are real regulatory
//  figures that change by law and need to be got right; hardcoding
//  them without a confirmed, currently-correct rate table would risk
//  quietly misleading a real company's statutory filings. Flagged
//  here, not hidden — a rate-table-driven calculator is real future
//  work, not an oversight.
//
//  advance_deduction_amount recovers an open Advances to Employees
//  (147) balance against this month's pay — capped by
//  PayrollRunService at whatever's actually outstanding.
//
//  ── payroll_run_line_items ────────────────────────────────────────
//  The generic addition/deduction shape. type='addition' has no
//  deduction_reason_id (a bonus doesn't need one); type='deduction'
//  requires one, and its behavior (earned_reduction vs
//  payout_penalty, see DeductionReason) decides which side of the
//  accrual it lands on — PayrollRunService reads that, this table
//  doesn't duplicate it.
//
//  ── payroll_run_postings ──────────────────────────────────────────
//  One row per expense category actually posted this run — a company
//  with no Marketing employees this month simply gets no Marketing
//  row, not a zero-amount entry. 'manufacturing' is its own category
//  here even though it has no salary expense account of its own (see
//  EmployeePayrollProfile header) — its posting clears 237 Direct
//  Labor Clearing instead, plugging 514 Manufacturing Labor Variance.
//
//  @layer    Migration
//  @depends  companies, partners, currencies, deduction_reasons,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('run_date');

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // One run per period per company — running the same
            // month twice is almost certainly a mistake, not a
            // legitimate second run.
            $table->unique(['company_id', 'period_year', 'period_month'], 'pr_company_period_unique');
        });

        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('employee_payroll_profile_id')->constrained('employee_payroll_profiles');

            // Snapshots — see class header.
            $table->decimal('base_salary', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->enum('expense_category', ['manufacturing', 'sales', 'marketing', 'general_admin']);

            // Computed by PayrollRunService from base_salary + this
            // line's own payroll_run_line_items — never entered
            // directly.
            $table->decimal('additions_total', 15, 2)->default(0);
            $table->decimal('earned_reduction_total', 15, 2)->default(0);
            $table->decimal('payout_penalty_total', 15, 2)->default(0);
            $table->decimal('gross_pay', 15, 2)->default(0);

            // Manual input this slice — see class header.
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('social_insurance_employee_amount', 15, 2)->default(0);
            $table->decimal('social_insurance_employer_amount', 15, 2)->default(0);

            $table->decimal('advance_deduction_amount', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_run_id', 'partner_id'], 'prl_run_partner_unique');
            $table->index(['company_id', 'partner_id']);
        });

        Schema::create('payroll_run_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_line_id')->constrained('payroll_run_lines')->cascadeOnDelete();

            $table->enum('type', ['addition', 'deduction']);
            $table->foreignId('deduction_reason_id')->nullable()->constrained('deduction_reasons')->restrictOnDelete();

            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'payroll_run_line_id']);
        });

        Schema::create('payroll_run_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();

            $table->enum('category', ['manufacturing', 'sales', 'marketing', 'general_admin']);
            $table->foreignId('journal_entry_id')->constrained('journal_entries');

            $table->timestamps();

            $table->unique(['payroll_run_id', 'category'], 'prp_run_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_postings');
        Schema::dropIfExists('payroll_run_line_items');
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
