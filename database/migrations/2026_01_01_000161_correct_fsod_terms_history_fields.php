<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000161_correct_fsod_terms_history_fields
//
//  Correction to migration 000159 (already migrated). Renames
//  cd_or_td_lending_pct to fsod_td_lending_pct for consistency with
//  000160, and adds fsod_td_amount alongside it — a renewal needs to
//  carry its own locked-in TD amount snapshot, not just its own
//  percentage, since either one (or both) can change at renewal time
//  and the chapter must reflect exactly what was true as of its
//  effective_date.
//
//  Unlike the LC facility (which has no terms-history tracking at
//  all yet — LC renewal is a later phase, confirmed separately),
//  Fully Secured OD renewal IS in scope from the start (confirmed
//  with Mahmoud), so both TD-derived numbers need a home on every
//  chapter, not just on the live facility row.
//
//  @layer    Migration
//  @depends  credit_facility_terms_histories (000134, 000159)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->renameColumn('cd_or_td_lending_pct', 'fsod_td_lending_pct');
        });

        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->decimal('fsod_td_amount', 15, 2)->nullable()->after('max_lending_limit_per_contract')
                  ->comment('Fully Secured OD only: this chapter\'s locked-in TD amount snapshot.');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->dropColumn('fsod_td_amount');
            $table->renameColumn('fsod_td_lending_pct', 'cd_or_td_lending_pct');
        });
    }
};
