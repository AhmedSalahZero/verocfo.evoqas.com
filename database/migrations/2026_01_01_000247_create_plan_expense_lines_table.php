<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000247_create_plan_expense_lines_table
//
//  Operating expense assumptions — one row per named expense item.
//  category maps directly onto the real Chart of Accounts Level 2
//  groups (52/53/54/55 — spec Section 3.2's confirmed mapping), and
//  expense_type mirrors CFO Tools' three input shapes exactly
//  (percent of revenue / fixed recurring / one-time amortized).
//
//  start_month/end_month are 0-based, same grain as
//  plan_sales_lines.period_month. end_month NULL = runs through the
//  end of the plan.
//
//  @layer    Migration
//  @depends  plans
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            $table->string('expense_name', 200);
            $table->enum('category', ['sales', 'marketing', 'general_admin', 'finance']);
            $table->enum('expense_type', ['pct_revenue', 'fixed_recurring', 'one_time']);

            // pct_revenue → a %; fixed_recurring → a monthly amount;
            // one_time → the total, amortized over amortization_months.
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('amortization_months')->default(1);

            $table->decimal('annual_increase_pct', 8, 4)->default(0);

            $table->unsignedSmallInteger('start_month')->default(0);
            $table->unsignedSmallInteger('end_month')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_expense_lines');
    }
};
