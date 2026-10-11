<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000163_add_renewal_rate_changes_to_lg_issuance_history
//
//  Confirmed with Mahmoud: an LG Issuance Renewal (NOT a Facility
//  Renewal) can also change two rates the bank sets independently of
//  the end-date extension:
//
//    - Cash Cover % — if the bank raises or lowers the required cash
//      cover at renewal, only the INCREMENTAL difference moves,
//      because the original cash cover is already sitting in 138001.
//      Increase → additional cash placed (Dr 138001 / Cr Bank).
//      Decrease → the difference is released back (Dr Bank / Cr 138001).
//    - Commission % — if the bank changes the commission rate at
//      renewal, the new rate applies PROSPECTIVELY ONLY, from the
//      renewal date forward. Nothing before the renewal date is
//      recalculated (postDueAccrualsForIssuance() always reads
//      commission_amount fresh at the moment of accrual, so simply
//      updating it here is sufficient — no separate "effective from"
//      column needed).
//
//  Both old_* and new_* pairs are stored explicitly (not just the
//  delta) so undoRenewal() can restore the exact pre-renewal values
//  with certainty rather than reverse-computing them — same principle
//  already used for old_current_amount on the Decrease event (see
//  000143 header).
//
//  new_cash_cover_pct / new_commission_rate_pct are also the ONLY
//  guard undoRenewal() needs: if either is NULL, that dimension
//  simply wasn't changed at this renewal, so there's nothing to
//  restore for it.
//
//  @layer    Migration
//  @depends  lg_issuance_history
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuance_history', function (Blueprint $table) {
            // Cash Cover rate change at renewal
            $table->decimal('old_cash_cover_pct', 5, 2)->nullable()->after('renewal_fee_amount');
            $table->decimal('new_cash_cover_pct', 5, 2)->nullable()->after('old_cash_cover_pct');
            $table->decimal('old_cash_cover_amount', 15, 2)->nullable()->after('new_cash_cover_pct');
            $table->decimal('new_cash_cover_amount', 15, 2)->nullable()->after('old_cash_cover_amount');
            $table->decimal('cash_cover_delta_amount', 15, 2)->nullable()->after('new_cash_cover_amount');

            // Commission rate change at renewal
            $table->decimal('old_commission_rate_pct', 6, 4)->nullable()->after('cash_cover_delta_amount');
            $table->decimal('new_commission_rate_pct', 6, 4)->nullable()->after('old_commission_rate_pct');
            $table->decimal('old_commission_amount', 15, 2)->nullable()->after('new_commission_rate_pct');
            $table->decimal('new_commission_amount', 15, 2)->nullable()->after('old_commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuance_history', function (Blueprint $table) {
            $table->dropColumn([
                'old_cash_cover_pct',
                'new_cash_cover_pct',
                'old_cash_cover_amount',
                'new_cash_cover_amount',
                'cash_cover_delta_amount',
                'old_commission_rate_pct',
                'new_commission_rate_pct',
                'old_commission_amount',
                'new_commission_amount',
            ]);
        });
    }
};
