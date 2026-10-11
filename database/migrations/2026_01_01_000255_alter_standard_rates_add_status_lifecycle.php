<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000255_alter_standard_rates_add_status_lifecycle
//
//  Standard Rate Draft/Active/Superseded + history fix — confirmed
//  with Mahmoud (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md,
//  Section 2). This one was worse than the BOM gap: setting a new
//  annual rate used updateOrCreate() keyed on (company, fiscal_year,
//  rate_type, overhead_pool_id) — every "set" OVERWROTE the previous
//  rate in place. There was no history at all: correct a rate in
//  November, and whatever applied from January to November is simply
//  gone, with no trace and no way to explain past variance using the
//  rate that actually governed it at the time.
//
//  FIX — same lifecycle pattern as BOM (migration 000254):
//    version         — this table's key (company, fiscal_year,
//                       rate_type, overhead_pool_id) can now have
//                       several rows over time instead of exactly one.
//    status          — draft | active | superseded. Draft is fully
//                       editable; Active is locked; Superseded is
//                       kept forever, read-only, for history.
//    effective_from  — the date this version's rate starts applying.
//                       StandardRateService::resolveEffective() picks
//                       whichever Active-or-Superseded version's
//                       effective_from is the latest one on or before
//                       the month being calculated — so correcting a
//                       rate never destroys what applied before the
//                       correction.
//
//  Existing rows: every row becomes status = 'active', version = 1
//  (there was at most one per key already, by the old upsert rule),
//  with effective_from backfilled from its own fiscal year's
//  start_date — this exactly preserves the old behavior ("one rate
//  for the whole fiscal year") for anything already in the system.
//
//  The old unique index only covered (company, fiscal_year, rate_type,
//  overhead_pool_id) — too narrow now that several versions can exist
//  per key. Replaced with the same four columns plus version.
//
//  @layer    Migration
//  @depends  standard_rates, fiscal_years
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_rates', function (Blueprint $table) {
            $table->dropUnique('sr_company_fy_type_pool');
            $table->unsignedInteger('version')->default(1)->after('overhead_pool_id');
            $table->enum('status', ['draft', 'active', 'superseded'])->default('active')->after('version');
            $table->date('effective_from')->nullable()->after('status');
        });

        // Preserves old behavior exactly: a rate used to apply for the
        // whole fiscal year, so its effective date becomes day one of
        // that year.
        DB::statement('
            UPDATE standard_rates sr
            INNER JOIN fiscal_years fy ON fy.id = sr.fiscal_year_id
            SET sr.effective_from = fy.start_date
            WHERE sr.effective_from IS NULL
        ');

        DB::statement('ALTER TABLE standard_rates MODIFY effective_from DATE NOT NULL');

        Schema::table('standard_rates', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id', 'version'], 'sr_company_fy_type_pool_version');
            $table->index(['company_id', 'fiscal_year_id', 'rate_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('standard_rates', function (Blueprint $table) {
            $table->dropUnique('sr_company_fy_type_pool_version');
            $table->dropIndex(['company_id', 'fiscal_year_id', 'rate_type', 'status']);
            $table->dropColumn(['version', 'status', 'effective_from']);
            $table->unique(['company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id'], 'sr_company_fy_type_pool');
        });
    }
};
