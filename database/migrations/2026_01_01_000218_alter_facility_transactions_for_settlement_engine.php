<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000218_alter_facility_transactions_for_settlement_engine
//
//  Two small additions the Facility Settlement Engine (Bible Rule 34)
//  needs on tables that already exist:
//
//  1. facility_liability_charges — today a charge is only ever
//     'pending' or 'posted', all-or-nothing. Rule 34.2's priority
//     buckets (interest, then HDB, then admin) need a charge to be
//     PARTIALLY settled too, exactly like a withdrawal already can
//     be — a EGP 1,500 interest charge might only get EGP 900 of a
//     small collection before the bucket moves on. Adding
//     settled_amount / remaining_amount, same shape as
//     facility_withdrawals already has.
//
//  2. facility_withdrawals — today only ever created by
//     seedOpeningWithdrawals() at contract setup (is_opening_balance
//     = true). A LIVE withdrawal (created by a Supplier Payment or
//     an Internal Transfer drawing on the facility) needs to know
//     what created it, so reversing that source transaction knows
//     which withdrawal to unwind. source_type / source_id added,
//     nullable — opening-balance rows leave both null.
//
//  @layer    Migration
//  @depends  facility_liability_charges, facility_withdrawals
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_liability_charges', function (Blueprint $table) {
            $table->decimal('settled_amount', 15, 2)->default(0)->after('actual_amount');
            $table->decimal('remaining_amount', 15, 2)->default(0)->after('settled_amount');
        });

        // Backfill: a charge already 'posted' with no settlement
        // history yet is fully outstanding; existing 'pending' rows
        // aren't liabilities until posted, so remaining_amount is
        // meaningless for them until posting sets it.
        DB::table('facility_liability_charges')
            ->where('status', 'posted')
            ->update(['remaining_amount' => DB::raw('actual_amount')]);

        Schema::table('facility_withdrawals', function (Blueprint $table) {
            $table->enum('source_type', ['opening_balance', 'supplier_payment', 'internal_money_transfer'])
                ->nullable()->after('is_opening_balance');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->index(['company_id', 'source_type', 'source_id'], 'fw_company_source');
        });

        // Backfill: every existing row today is an opening-balance
        // seed (confirmed — no recordWithdrawal() has ever run yet).
        DB::table('facility_withdrawals')->update(['source_type' => 'opening_balance']);
    }

    public function down(): void
    {
        Schema::table('facility_withdrawals', function (Blueprint $table) {
            $table->dropIndex('fw_company_source');
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('facility_liability_charges', function (Blueprint $table) {
            $table->dropColumn(['settled_amount', 'remaining_amount']);
        });
    }
};
