<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000069_alter_lg_issuances_add_renewed_at
//
//  Adds a visible "Renewed" marker separate from status. Confirmed
//  default: status stays running/expired/cancelled only — this
//  column just answers "has this ever been renewed", stamped with
//  the date of the MOST RECENT renewal (LgIssuanceService::renew()
//  updates it every time). Full renewal history with old/new dates
//  and fees still lives in lg_issuance_history — this is purely a
//  fast, indexable flag for the index table badge.
//
//  @layer    Migration
//  @depends  lg_issuances
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->date('renewed_at')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->dropColumn('renewed_at');
        });
    }
};
