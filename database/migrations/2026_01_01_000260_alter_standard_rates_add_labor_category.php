<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000260_alter_standard_rates_add_labor_category
//
//  Labor Categories (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
//  Section 3) — extends the shared StandardRate mechanism (migration
//  000088) with a third rate_type: 'labor_category'. Each Labor
//  Category gets its own Draft/Active/Superseded, effective-dated
//  rate — the exact same lifecycle 'overhead_pool' rates already have
//  (migration 000255), just paired with labor_category_id instead of
//  overhead_pool_id.
//
//  The existing 'labor' rate_type (a single blanket company-wide
//  rate, overhead_pool_id and labor_category_id both null) is left
//  completely untouched — it keeps working exactly as before for
//  BOM's current single "standard labor hours per batch" field. Both
//  mechanisms coexist until Section 5 (Routing) replaces that field
//  with per-category hours and retires the blanket rate; nothing here
//  forces that migration to happen at the same time.
//
//  labor_category_id follows the exact same nullable-FK-alongside-
//  rate_type pattern overhead_pool_id already established — only
//  ever set when rate_type = 'labor_category', enforced in
//  StandardRateService, not at the DB level.
//
//  The unique index and the "which version applies" resolution query
//  both need to widen to include labor_category_id, for the same
//  reason they already include overhead_pool_id: two different rate
//  types (or two different categories/pools) must never collide as
//  if they were the same key.
//
//  @layer    Migration
//  @depends  standard_rates, labor_categories
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_rates', function (Blueprint $table) {
            $table->dropUnique('sr_company_fy_type_pool_version');

            $table->foreignId('labor_category_id')->nullable()->after('overhead_pool_id')
                  ->constrained('labor_categories')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE standard_rates MODIFY rate_type ENUM('labor', 'overhead_pool', 'labor_category') NOT NULL");

        Schema::table('standard_rates', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id', 'labor_category_id', 'version'],
                'sr_company_fy_type_pool_category_version'
            );
        });
    }

    public function down(): void
    {
        Schema::table('standard_rates', function (Blueprint $table) {
            $table->dropUnique('sr_company_fy_type_pool_category_version');
            $table->dropForeign(['labor_category_id']);
            $table->dropColumn('labor_category_id');
        });

        DB::statement("ALTER TABLE standard_rates MODIFY rate_type ENUM('labor', 'overhead_pool') NOT NULL");

        Schema::table('standard_rates', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id', 'version'], 'sr_company_fy_type_pool_version');
        });
    }
};
