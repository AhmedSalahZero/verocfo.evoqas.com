<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000159_add_lending_pct_to_credit_facility_terms_histories
//
//  Fully Secured Overdraft — Setup Phase, part 2 (confirmed with
//  Mahmoud, August 2026: renewal history included from the start).
//
//  Adds cd_or_td_lending_pct to the already-shared
//  credit_facility_terms_histories table, exactly the same way
//  max_lending_limit_per_customer / max_lending_limit_per_contract
//  were added for Commercial Paper OD and Contract-Assignment OD in
//  migration 000134 — one shared "chapters" table, with a handful of
//  nullable, type-specific columns that only that one facility_type
//  ever populates. NULL for every other facility_type's chapters.
//
//  No backfill needed here: unlike migration 000134 (which backfilled
//  Clean/CP/Contract-SO facilities that already existed before terms
//  history was introduced), no fully_secured facility exists yet —
//  the facility_type itself was only just added in migration 000158.
//  The very first Fully Secured OD ever created will get its
//  "Original" chapter row the normal way, through
//  FullySecuredOverdraftService::create() (Step 3), same as every
//  other OD type's create() already does.
//
//  @layer    Migration
//  @depends  credit_facility_terms_histories (000134)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->decimal('cd_or_td_lending_pct', 5, 2)
                  ->nullable()
                  ->after('max_lending_limit_per_contract')
                  ->comment('Fully Secured OD only: this chapter\'s locked-in % of the linked TD value.');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->dropColumn('cd_or_td_lending_pct');
        });
    }
};
