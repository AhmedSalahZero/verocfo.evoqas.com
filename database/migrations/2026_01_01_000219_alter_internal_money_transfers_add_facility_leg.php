<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000219_alter_internal_money_transfers_add_facility_leg
//
//  Adds 'facility' as a third leg type alongside 'bank' and 'safe'
//  (confirmed with Mahmoud — Internal Money Transfer is the one
//  screen that can move money into or out of an OD-family facility
//  directly, not a separate manual settlement screen).
//
//  Direction determines what it means to the facility:
//    from_leg_type = 'facility'  → the OD is funding the other leg
//                                   → a DRAWDOWN (FacilitySettlement-
//                                     Engine::recordWithdrawal())
//    to_leg_type   = 'facility'  → money is paying the OD down
//                                   → a SETTLEMENT (…::recordSettlement())
//
//  from_leg_type is a plain string column (not a DB enum — see
//  migration 000140), so no MySQL MODIFY is needed here, just the
//  new nullable FK columns. InternalMoneyTransferService validates
//  the allowed values in PHP (assertLegsValid()), same as today.
//
//  Timing (per InternalMoneyTransferService's existing instant vs.
//  in-transit design): a from-facility leg always posts immediately
//  in record() (the source leg is never delayed). A to-facility leg
//  posts — and therefore settles — at record() for an instant
//  transfer, or at confirmReceived() for an in-transit one. The
//  engine call happens at the exact same moment the facility's own
//  ledger line is journaled, never before.
//
//  @layer    Migration
//  @depends  internal_money_transfers, credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_money_transfers', function (Blueprint $table) {
            $table->foreignId('from_credit_facility_id')->nullable()
                ->after('from_safe_account_id')->constrained('credit_facilities')->nullOnDelete();
            $table->foreignId('to_credit_facility_id')->nullable()
                ->after('to_safe_account_id')->constrained('credit_facilities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('internal_money_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_credit_facility_id');
            $table->dropConstrainedForeignId('to_credit_facility_id');
        });
    }
};
