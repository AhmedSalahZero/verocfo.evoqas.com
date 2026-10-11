<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000088_create_standard_rates_table
//
//  Mass Production — Layer 2, piece 3 of 5. ONE shared mechanism for
//  both labor and overhead pool rates, confirmed with Mahmoud rather
//  than building two parallel "annual rate" tables — both follow the
//  exact same rule (Scope §5.4): "standard rates set annually,
//  overridable per month."
//
//  rate_type = 'labor'          → one row per fiscal year, the
//                                  standard cost per labor hour.
//                                  overhead_pool_id is NULL.
//  rate_type = 'overhead_pool'  → one row per fiscal year PER POOL,
//                                  overhead_pool_id set.
//
//  "One labor rate per fiscal year" is NOT enforced by the unique
//  index below — MySQL allows multiple NULLs in a unique composite
//  index, so several rate_type='labor' rows (overhead_pool_id NULL)
//  for the same fiscal year would not collide at the DB level. This
//  is enforced in StandardRateService instead, same pattern as
//  "only one active BOM version" being an application-level rule,
//  not a DB constraint.
//
//  fiscal_year_id (not a raw year integer) — this company allows
//  multiple open fiscal years simultaneously (Egyptian practice, per
//  Building Bible), so a plain year number would be ambiguous;
//  tying the rate to the actual fiscal_years row it belongs to is
//  unambiguous.
//
//  Monthly overrides live in standard_rate_monthly_overrides
//  (migration 000089) — a separate table so the annual rate itself
//  never has to be destructively edited to record a one-month
//  exception.
//
//  @layer    Migration
//  @depends  companies, fiscal_years, overhead_pools
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();

            $table->enum('rate_type', ['labor', 'overhead_pool']);
            $table->foreignId('overhead_pool_id')->nullable()->constrained('overhead_pools')->cascadeOnDelete();

            $table->decimal('annual_rate', 15, 4); // EGP per labor hour, or EGP per allocation-base unit for a pool

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id'], 'sr_company_fy_type_pool');
            $table->index(['company_id', 'rate_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_rates');
    }
};
