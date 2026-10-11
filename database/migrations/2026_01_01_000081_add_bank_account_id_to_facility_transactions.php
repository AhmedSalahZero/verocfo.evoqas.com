<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000081_add_bank_account_id_to_facility_transactions
//
//  Adds bank_account_id directly onto every facility-transaction row
//  that was missing it: facility_withdrawals, facility_liability_
//  charges, and lease_cheques.
//
//  WHY (confirmed with Mahmoud — this is a non-negotiable, permanent
//  rule, not a one-off preference — see Building Bible Rule 31):
//  Vero CFO is a treasury system as much as an accounting one. Every
//  transaction that touches a facility must carry, on its own row,
//  which bank account the cash actually moved through — not only
//  inferred by looking up the parent facility's CURRENT bank account.
//  Two concrete reasons this must live on the transaction itself:
//    1. Interest/fee calculations must be traceable and reconcilable
//       against the bank's own statement for that specific account.
//    2. "Statement by bank account" reports must run directly off the
//       transaction table — not require a join back to a parent whose
//       bank account could theoretically be reassigned later.
//
//  This migration was deliberately NOT scoped to "just Leasing" —
//  Overdraft-family facilities (facility_withdrawals,
//  facility_liability_charges) need this exactly as much as Leasing
//  and LG/MTL (which already had it correctly — see
//  lg_issuance_movements.debit_bank_account_id/credit_bank_account_id
//  and mtl_settlements.current_account_id, both already correct
//  models to match going forward).
//
//  Backfill: every existing row is populated from its parent's
//  CURRENT bank_account_id at migration time. This is safe today
//  because no facility or leasing contract has ever had more than
//  one bank_account_id assigned to it in this application's history —
//  so backfilling from "current" and "historically correct" are the
//  same thing right now. Nullable because a small number of facilities
//  may have been created without a bank_account_id set at all; any
//  row that backfills to NULL is flagged for manual review, not
//  silently left incorrect (see the printed summary at the end of
//  this migration's up() method).
//
//  @layer    Migration
//  @depends  facility_withdrawals, facility_liability_charges,
//            lease_cheques, credit_facilities, leasing_contracts,
//            bank_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('credit_facility_id');
        });

        Schema::table('facility_liability_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('credit_facility_id');
        });

        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('leasing_contract_id');
        });

        DB::statement('
            UPDATE facility_withdrawals fw
            JOIN credit_facilities cf ON cf.id = fw.credit_facility_id
            SET fw.bank_account_id = cf.bank_account_id
            WHERE cf.bank_account_id IS NOT NULL
        ');

        DB::statement('
            UPDATE facility_liability_charges flc
            JOIN credit_facilities cf ON cf.id = flc.credit_facility_id
            SET flc.bank_account_id = cf.bank_account_id
            WHERE cf.bank_account_id IS NOT NULL
        ');

        DB::statement('
            UPDATE lease_cheques lc
            JOIN leasing_contracts lct ON lct.id = lc.leasing_contract_id
            SET lc.bank_account_id = lct.bank_account_id
            WHERE lct.bank_account_id IS NOT NULL
        ');

        // ── Indexes — company_id + FK column, per Bible Section 4 ───

        Schema::table('facility_withdrawals', function (Blueprint $table) {
            $table->index(['company_id', 'bank_account_id'], 'fw_company_bank_account');
        });

        Schema::table('facility_liability_charges', function (Blueprint $table) {
            $table->index(['company_id', 'bank_account_id'], 'flc_company_bank_account');
        });

        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->index(['company_id', 'bank_account_id'], 'lc_company_bank_account');
        });

        // ── Flag anything that could not be backfilled ──────────────
        // (facilities/contracts that themselves had no bank_account_id
        // set — a pre-existing data-quality gap, not something this
        // migration can fix on its own. Surfaced here so it isn't
        // silently missed.)

        $unresolvedWithdrawals = DB::table('facility_withdrawals')->whereNull('bank_account_id')->count();
        $unresolvedCharges     = DB::table('facility_liability_charges')->whereNull('bank_account_id')->count();
        $unresolvedCheques     = DB::table('lease_cheques')->whereNull('bank_account_id')->count();

        if ($unresolvedWithdrawals || $unresolvedCharges || $unresolvedCheques) {
            echo "\n";
            echo "  ⚠ Some rows could not be backfilled because their parent facility/contract\n";
            echo "    has no bank_account_id set at all — please review and set manually:\n";
            echo "      facility_withdrawals:        {$unresolvedWithdrawals} row(s)\n";
            echo "      facility_liability_charges:  {$unresolvedCharges} row(s)\n";
            echo "      lease_cheques:               {$unresolvedCheques} row(s)\n";
            echo "\n";
        }
    }

    public function down(): void
    {
        Schema::table('facility_withdrawals', function (Blueprint $table) {
            $table->dropIndex('fw_company_bank_account');
            $table->dropColumn('bank_account_id');
        });

        Schema::table('facility_liability_charges', function (Blueprint $table) {
            $table->dropIndex('flc_company_bank_account');
            $table->dropColumn('bank_account_id');
        });

        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->dropIndex('lc_company_bank_account');
            $table->dropColumn('bank_account_id');
        });
    }
};
