<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000187_add_settlement_bank_accounts_to_factoring_transactions
//
//  Collect/Reject each need their own bank account — the factoring
//  company's settlement might land in a different account than the
//  original disbursement. Confirmed with Mahmoud: default is the
//  same account used for the original disbursement, with the option
//  to override at Collect/Reject time — so both columns are nullable
//  and the Service fills them from bank_account_id when the user
//  doesn't override.
//
//  @layer    Migration
//  @depends  factoring_transactions, bank_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factoring_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('collection_bank_account_id')->nullable()->after('collection_journal_entry_id');
            $table->unsignedBigInteger('rejection_bank_account_id')->nullable()->after('rejection_journal_entry_id');

            $table->foreign('collection_bank_account_id', 'ftx_collection_bank_fk')
                  ->references('id')->on('bank_accounts');
            $table->foreign('rejection_bank_account_id', 'ftx_rejection_bank_fk')
                  ->references('id')->on('bank_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('factoring_transactions', function (Blueprint $table) {
            $table->dropForeign('ftx_collection_bank_fk');
            $table->dropForeign('ftx_rejection_bank_fk');
            $table->dropColumn(['collection_bank_account_id', 'rejection_bank_account_id']);
        });
    }
};
