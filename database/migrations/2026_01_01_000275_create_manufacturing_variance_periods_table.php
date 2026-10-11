<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_manufacturing_variance_periods_table
//
//  Manufacturing Variance Closing Design v1.0, Section 1/Step 3 —
//  PayrollRunService already CALCULATES the exact labor variance
//  (one company-wide figure — Option B, confirmed with Mahmoud) and
//  each Overhead Pool's own variance every time a Payroll Run posts,
//  but until now those numbers were used once to build a journal
//  entry and then discarded — nothing kept a queryable record of
//  "this pool's variance was exactly this amount, for this month."
//  This table is that record — the starting point the monthly
//  Variance Closing step reads from to push these numbers down to
//  individual orders and, from there, to WIP/COGS/Finished Goods.
//
//  One row per (company, period, variance_type, overhead_pool_id) —
//  overhead_pool_id is null for the single labor row (Option B: labor
//  variance is not split by Labor Category, only overhead is split by
//  pool, since only Overhead employees carry a pool assignment on
//  their payroll profile today).
//
//  @layer    Migration
//  @depends  companies, payroll_runs, overhead_pools
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_variance_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->enum('variance_type', ['labor', 'overhead']);
            $table->foreignId('overhead_pool_id')->nullable()->constrained('overhead_pools')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->unique(
                ['company_id', 'period_year', 'period_month', 'variance_type', 'overhead_pool_id'],
                'mfg_variance_periods_unique'
            );
            $table->index(['company_id', 'period_year', 'period_month'], 'mfg_variance_periods_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_variance_periods');
    }
};
