<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000239_create_plans_table
//
//  One row per Annual Plan or Business Plan & Valuation — the shared
//  Setup record both products are built on (VeroCFO_Financial_Study_
//  AnnualPlan_BusinessPlan_Valuation_Structure_v1.1, Section 2). The
//  wizard steps that follow (Sales/COGS/Manpower/Expenses/Fixed
//  Assets) all hang off plan_id, never a separate table per plan_type
//  — plan_type is the one flag that changes which fields are required
//  and which Results/Valuation sections render, not a fork in the
//  schema.
//
//  plan_type = 'annual'         → duration_years locked to 1,
//                                  wacc_pct/perpetual_growth_pct/
//                                  valuation_basis/valuation_multiplier
//                                  always NULL — no valuation for a
//                                  12-month operating budget.
//  plan_type = 'business_plan'  → duration_years 3–10, the above four
//                                  fields collected at Setup and used
//                                  by the Valuation page (DCF +
//                                  Multiplier — Section 4 of the spec).
//
//  valuation_basis/valuation_multiplier: the Multiplier Valuation
//  inputs are deliberately simple per the confirmed design — one
//  basis (revenue/ebitda/net_profit), one multiplier number, applied
//  to Year 1 of the plan's own results. No comparable-transactions
//  list, no Net Debt adjustment — see spec Section 4.2.
//
//  opening_balance_source: 'auto' (default) pulls the real starting
//  position live from BalanceSheetService/AccountBalanceCalculator as
//  of opening_balance_as_of_date (spec Section 3.3) — 'manual' is the
//  fallback for a genuinely new company with no ledger history yet.
//  Never re-entry of a real company's own real balances by hand.
//
//  @layer    Migration
//  @depends  companies, currencies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('plan_code', 20);
            $table->enum('plan_type', ['annual', 'business_plan']);
            $table->string('name', 255);

            $table->foreignId('currency_id')->constrained('currencies');
            $table->date('start_date');
            $table->unsignedTinyInteger('duration_years');
            $table->date('end_date');

            $table->enum('business_type', ['manufacturing', 'trading', 'service', 'mixed'])
                ->default('trading');

            $table->decimal('corporate_tax_rate', 8, 4)->default(0);

            // Business Plan & Valuation only — NULL for plan_type = 'annual'.
            $table->decimal('wacc_pct', 8, 4)->nullable();
            $table->decimal('perpetual_growth_pct', 8, 4)->nullable();

            // Multiplier Valuation inputs — Business Plan & Valuation only.
            $table->enum('valuation_basis', ['revenue', 'ebitda', 'net_profit'])->nullable();
            $table->decimal('valuation_multiplier', 8, 2)->nullable();

            $table->enum('opening_balance_source', ['auto', 'manual', 'none'])->default('auto');
            $table->date('opening_balance_as_of_date')->nullable();

            $table->enum('status', ['draft', 'completed'])->default('draft');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'plan_code']);
            $table->index(['company_id', 'plan_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
