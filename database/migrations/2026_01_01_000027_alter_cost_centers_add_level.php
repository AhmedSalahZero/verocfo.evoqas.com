<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000027_alter_cost_centers_add_level
//
//  Extends cost_centers for the two-level Main/Sub structure.
//
//  Changes:
//  1. Add `level` TINYINT — 1 = Main Cost Center, 2 = Sub Cost Center
//  2. Drop `type` VARCHAR — replaced by level (Main/Sub is the only
//     distinction needed; type was department/branch/project which
//     added complexity without analytical value)
//
//  Posting rule:
//    - Main Cost Center with NO children → postable
//    - Main Cost Center WITH children   → not postable (rollup only)
//    - Sub Cost Center                  → always postable
//  This is enforced in CostCenterService, not at DB level.
//
//  @layer    Migration
//  @depends  cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            // Add level column — 1 = Main, 2 = Sub
            $table->unsignedTinyInteger('level')
                  ->default(1)
                  ->after('parent_id');

            // Add index for level queries
            $table->index(['company_id', 'level']);

            // Drop type — no longer needed
            $table->dropIndex(['company_id', 'type']);
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'level']);
            $table->dropColumn('level');
            $table->string('type', 20)->default('department')->after('name_ar');
            $table->index(['company_id', 'type']);
        });
    }
};
