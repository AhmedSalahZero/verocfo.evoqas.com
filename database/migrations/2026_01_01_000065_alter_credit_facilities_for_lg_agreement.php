<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000065_alter_credit_facilities_for_lg_agreement
//
//  Two changes needed to support Letter of Guarantee as a
//  facility/agreement-level record (rate card by LG type, see
//  facility_lg_types) rather than a single-instrument contract:
//
//  1. `name` — new nullable label field ("LG Contract Year May
//     2024"). Doesn't fit `facility_reference` (that's the bank's
//     own reference number) or `purpose` (free-text notes) — this
//     is the person's own label for the agreement, shown as the
//     page title. Available to every facility type, not LG-only,
//     in case other types want it later.
//
//  2. `coa_account_id` → made nullable. It was NOT NULL because
//     every OD-family facility type (Clean OD, CP OD, Contract/SO)
//     posts to a single user-picked COA sub-account chosen at
//     contract setup. Letter of Guarantee doesn't work that way —
//     it always posts to the fixed 227/138 pair, and which COA
//     sub-account is relevant only becomes meaningful at the
//     individual LG issuance level (not built yet — confirmed with
//     Mahmoud this is an Issuance-layer concern, not an Agreement-
//     layer one). Left null for facility_type = 'letter_guarantee';
//     unaffected for every other facility type, which still
//     requires it via application-level validation.
//
//  Note: lg_beneficiary, lg_margin_pct, lg_cash_cover_account_id,
//  and expiry_date are NOT touched here. They were built for a
//  single-instrument LG model that no longer matches the confirmed
//  business rule (one agreement → many issuances). They stay
//  dormant/nullable and harmless until the LG Issuance table is
//  designed — deliberately not guessing at that shape now.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->string('name', 200)->nullable()->after('facility_reference');
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('coa_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('coa_account_id')->nullable(false)->change();
        });
    }
};
