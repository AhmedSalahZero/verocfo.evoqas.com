<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000227_create_deduction_reasons_table
//
//  Company-configurable lookup list for payroll deduction lines —
//  same pattern as Product Categories / Payment Terms (seeded with
//  sensible defaults, extensible per company). Confirmed with
//  Mahmoud: a dropdown of reasons, not a link to a real leave-
//  request/disciplinary-case record that doesn't exist yet — Payroll
//  only needs to know "addition or deduction, how much, which
//  reason" (see payroll_run_lines, migration 000228). Once full HR
//  eventually adds Leave Management, that module becomes a new
//  SOURCE writing the same generic line shape — this lookup list
//  doesn't need to change.
//
//  behavior is the one piece of real logic a reason carries — set
//  ONCE when the reason is created, so whoever runs payroll each
//  month never has to judge it themselves (confirmed with Mahmoud):
//    earned_reduction → reduces what was EARNED (e.g. unpaid leave)
//                        — shrinks the Dr Salary Expense side of the
//                        accrual itself.
//    payout_penalty    → the employee still earned the full salary;
//                        this only reduces what reaches them this
//                        month — stays entirely on the Cr Salaries
//                        Payable side, never touches the expense.
//
//  @layer    Migration
//  @depends  companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deduction_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name_en');
            $table->string('name_ar')->nullable();

            $table->enum('behavior', ['earned_reduction', 'payout_penalty']);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_reasons');
    }
};
