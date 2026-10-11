<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000158_add_fully_secured_od_to_credit_facilities
//
//  Fully Secured Overdraft — Setup Phase (confirmed with Mahmoud,
//  August 2026). Ported from CashVero's fully_secured_overdrafts
//  concept, rebuilt onto Vero CFO's already-unified credit_facilities
//  table instead of CashVero's separate one-table-per-type design —
//  same reasoning already applied to Clean OD / CP OD / Contract-SO OD.
//
//  What's different about this OD type: the limit isn't a number the
//  bank just agrees to (like Clean OD's facility_limit). It's secured
//  against a specific Time Deposit account the company holds:
//
//      facility_limit = linked TD's td_amount × cd_or_td_lending_pct / 100
//
//  That calculation happens in FullySecuredOverdraftService (Step 3,
//  not yet built) and its RESULT is stored in the existing
//  facility_limit column — no new "limit" column needed here.
//  Confirmed with Mahmoud: the value is locked in at creation/renewal
//  time (matches how banks actually issue these contracts), not
//  recalculated live off the TD's current balance.
//
//  New columns (both nullable — only meaningful for
//  facility_type = 'fully_secured', same pattern as the
//  Contract/SO-only and Commercial-Paper-only columns already on
//  this table):
//
//    cd_or_td_bank_account_id — which bank_accounts row (must be
//      account_type = 'time_deposit') secures this facility. Named
//      "cd_or_td" (not just "td") to match CashVero's naming and
//      leave room for Certificate of Deposit later — Vero CFO has no
//      CD account type yet (confirmed out of scope for this pass;
//      Time Deposit only for now).
//
//    cd_or_td_lending_pct — the percentage the bank agrees to lend
//      against that TD's value (e.g. 80.00 = 80%). Locked in at
//      create/renew, same as facility_limit.
//
//  facility_type enum: adding 'fully_secured' alongside the existing
//  eight values. MySQL requires the full enum list to be restated —
//  this is a straight ALTER, no data migration needed since no rows
//  use the new value yet.
//
//  @layer    Migration
//  @depends  credit_facilities (000043), bank_accounts (000042)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Restate facility_type with 'fully_secured' added — MySQL
        // requires the full enum list on every ALTER, not just the
        // new value.
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE credit_facilities MODIFY facility_type ENUM(
                'clean_od', 'commercial_paper', 'contract_so',
                'cheque_discount', 'import_lc', 'letter_guarantee',
                'mtl', 'stl', 'fully_secured'
            ) NOT NULL"
        );

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('cd_or_td_bank_account_id')
                  ->nullable()
                  ->after('collateral_status')
                  ->comment('Fully Secured OD only: the Time Deposit bank_accounts row securing this facility.');

            $table->decimal('cd_or_td_lending_pct', 5, 2)
                  ->nullable()
                  ->after('cd_or_td_bank_account_id')
                  ->comment('Fully Secured OD only: % of the linked TD value the bank lends. Locked in at create/renew.');

            $table->foreign('cd_or_td_bank_account_id', 'cf_cd_or_td_account_fk')
                  ->references('id')->on('bank_accounts')
                  ->restrictOnDelete();

            $table->index(['company_id', 'cd_or_td_bank_account_id'], 'cf_cd_or_td_account_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropForeign('cf_cd_or_td_account_fk');
            $table->dropIndex('cf_cd_or_td_account_idx');
            $table->dropColumn(['cd_or_td_bank_account_id', 'cd_or_td_lending_pct']);
        });

        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE credit_facilities MODIFY facility_type ENUM(
                'clean_od', 'commercial_paper', 'contract_so',
                'cheque_discount', 'import_lc', 'letter_guarantee',
                'mtl', 'stl'
            ) NOT NULL"
        );
    }
};
