<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000160_correct_fully_secured_od_fields_to_match_lc
//
//  Correction to migration 000158 (already migrated — this is a new
//  migration, not an edit, since an applied migration can't be
//  changed after the fact).
//
//  Reason: migration 000158 invented its own two-column shape
//  (cd_or_td_bank_account_id, cd_or_td_lending_pct) without first
//  checking that Vero CFO already has an established, Mahmoud-
//  approved pattern for "facility secured by a pledged Time Deposit"
//  — the Letter of Credit fully_secured fields from migration 000072
//  (lc_td_account_id, lc_td_currency_id, lc_td_amount,
//  lc_td_interest_rate, lc_td_lending_pct). This migration drops the
//  000158 columns and replaces them with the same five-column shape,
//  renamed with an fsod_ prefix (Fully Secured Overdraft) instead of
//  lc_:
//
//    fsod_td_account_id    — the pledged bank_accounts row
//                             (account_type = 'time_deposit')
//    fsod_td_currency_id   — the TD's own currency (can differ from
//                             the facility's own currency_id)
//    fsod_td_amount        — snapshot of the TD's value at the
//                             moment the limit was locked in
//    fsod_td_interest_rate — informational only, what the TD itself
//                             earns — not used in any OD calculation
//    fsod_td_lending_pct   — the bank's haircut; drives the computed
//                             limit written into facility_limit
//
//  facility_limit = fsod_td_amount × fsod_td_lending_pct ÷ 100,
//  computed and stored by FullySecuredOverdraftService — same
//  single-source-of-truth approach LC already uses.
//
//  @layer    Migration
//  @depends  credit_facilities (000043, 000158), bank_accounts, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropForeign('cf_cd_or_td_account_fk');
            $table->dropIndex('cf_cd_or_td_account_idx');
            $table->dropColumn(['cd_or_td_bank_account_id', 'cd_or_td_lending_pct']);
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->foreignId('fsod_td_account_id')->nullable()->after('collateral_status')
                  ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('fsod_td_currency_id')->nullable()->after('fsod_td_account_id')
                  ->constrained('currencies')->nullOnDelete();
            $table->decimal('fsod_td_amount', 15, 2)->nullable()->after('fsod_td_currency_id');
            $table->decimal('fsod_td_interest_rate', 6, 4)->nullable()->after('fsod_td_amount');
            $table->decimal('fsod_td_lending_pct', 5, 2)->nullable()->after('fsod_td_interest_rate');

            $table->index(['company_id', 'fsod_td_account_id'], 'cf_fsod_td_account_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropIndex('cf_fsod_td_account_idx');
            $table->dropConstrainedForeignId('fsod_td_account_id');
            $table->dropConstrainedForeignId('fsod_td_currency_id');
            $table->dropColumn(['fsod_td_amount', 'fsod_td_interest_rate', 'fsod_td_lending_pct']);
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('cd_or_td_bank_account_id')->nullable()->after('collateral_status');
            $table->decimal('cd_or_td_lending_pct', 5, 2)->nullable()->after('cd_or_td_bank_account_id');

            $table->foreign('cd_or_td_bank_account_id', 'cf_cd_or_td_account_fk')
                  ->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->index(['company_id', 'cd_or_td_bank_account_id'], 'cf_cd_or_td_account_idx');
        });
    }
};
