<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000165_add_lc_and_min_interest_to_terms_histories
//
//  Letter of Credit Facility Renewal — the gap Mahmoud flagged: LC
//  had no renewal feature at all, unlike Clean OD / CP OD / Contract
//  Assignment OD / Fully Secured OD, which already renew via the
//  shared credit_facility_terms_histories "chapters" table.
//
//  Two columns genuinely new to that shared table:
//
//    lc_td_amount / lc_td_lending_pct — mirrors the fsod_td_amount /
//    fsod_td_lending_pct pair already added for Fully Secured OD
//    (migrations 000159/000161), same formula, same reasoning: for a
//    Fully Secured LC (lc_security_type = 'fully_secured'),
//    facility_limit is COMPUTED = lc_td_amount × lc_td_lending_pct ÷
//    100, not typed in directly — confirmed in Mahmoud's own renewal
//    mockup ("This facility is Fully Secured — the Limit is
//    recalculated automatically from the linked CD/TD amount × this
//    percentage"). Named lc_ (not reusing fsod_) to match the live
//    credit_facilities table's own naming split between the two
//    facility types (lc_td_* vs fsod_td_*, see migration 000160's
//    reasoning) — a chapter column should read the same as the live
//    column it snapshots.
//
//    min_interest_rate — already exists on credit_facilities itself
//    (migration 000052, shared OD-era column) but was never added to
//    the chapters table by any prior renewal build (Clean OD's own
//    renewal doesn't cover it either — confirmed by inspecting
//    RenewCleanOverdraftRequest). LC's renewal mockup surfaces it
//    explicitly ("New Min Interest Rate (%)"), so it needs a home
//    here now.
//
//  Bug fix bundled in the same migration (found while building this):
//  CreditFacilityTermsHistory's $fillable never included
//  fsod_td_amount / fsod_td_lending_pct even though
//  CreditFacility::renew() has been passing them to termsHistories()
//  ->create() since migration 000161 — every Fully Secured OD
//  renewal chapter has been silently storing NULL for both columns.
//  Fixed in the model alongside this migration (see
//  CreditFacilityTermsHistory.php) since no schema change is needed
//  for that half of the fix, only exposing the already-existing
//  columns to mass assignment.
//
//  @layer    Migration
//  @depends  credit_facility_terms_histories (000134, 000159, 000161)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->decimal('min_interest_rate', 8, 4)->nullable()->after('bank_margin_rate');
            $table->decimal('lc_td_amount', 15, 2)->nullable()->after('fsod_td_amount')
                  ->comment('Letter of Credit (Fully Secured) only: this chapter\'s locked-in TD amount snapshot.');
            $table->decimal('lc_td_lending_pct', 5, 2)->nullable()->after('lc_td_amount')
                  ->comment('Letter of Credit (Fully Secured) only: this chapter\'s locked-in % of the linked TD value.');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facility_terms_histories', function (Blueprint $table) {
            $table->dropColumn(['min_interest_rate', 'lc_td_amount', 'lc_td_lending_pct']);
        });
    }
};
