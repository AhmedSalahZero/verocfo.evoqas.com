<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000053_alter_credit_facilities_add_corridor_rate
//
//  Adds follows_corridor_rate boolean to credit_facilities.
//  When true: borrowing_rate updates automatically when CBE
//  changes the Corridor Rate (via CBE Corridor Rate settings page).
//  Interest recalculates from the effective date — daily split.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->boolean('follows_corridor_rate')->default(false)->after('settled_max_days');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn('follows_corridor_rate');
        });
    }
};
