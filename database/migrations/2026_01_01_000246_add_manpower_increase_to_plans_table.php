<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000246_add_manpower_increase_to_plans_table
//
//  One annual salary increase %, applied plan-wide to every manpower
//  title (existing + newly hired) — confirmed design, Sept 2026.
//  Added as its own alter migration rather than folded into
//  000239_create_plans_table, per Rule 5 (never modify an existing
//  migration once written).
//
//  @layer    Migration
//  @depends  plans
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('manpower_annual_increase_pct', 8, 4)->default(0)->after('perpetual_growth_pct');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('manpower_annual_increase_pct');
        });
    }
};
