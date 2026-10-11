<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000191_alter_cheques_payable_for_reverse_factoring
//
//  Reverse Factoring (August 2026) — a 4th possible issuer of a
//  payable cheque, alongside Supplier Payment / Other Payment / Cash
//  Expense Payment (migrations 139/175/178). Same "exactly one of the
//  source_*_id columns is set" rule, enforced in
//  ChequePayableService, not the database.
//
//  Two real differences from the first three sources:
//    1. NOT unique — a single Reverse Factoring transaction can issue
//       SEVERAL cheques covering one liability (a repeater on the
//       Create form), unlike the strict 1:1 the other three sources
//       use. reverse_factoring_transaction_id is a plain (non-unique)
//       foreign key.
//    2. The payee is a Factoring Company, not necessarily a Partner
//       — factoring_company_id is added, and partner_id (which every
//       other cheques_payable row still has, denormalized from its
//       supplier) is loosened to nullable for this one source only.
//
//  @layer    Migration
//  @depends  cheques_payable, reverse_factoring_transactions,
//            financial_institutions (factoring_companies)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->unsignedBigInteger('reverse_factoring_transaction_id')->nullable()->after('cash_expense_payment_id');
            $table->unsignedBigInteger('factoring_company_id')->nullable()->after('partner_id');

            $table->foreign('reverse_factoring_transaction_id', 'chq_pay_rev_factoring_fk')
                  ->references('id')->on('reverse_factoring_transactions')->cascadeOnDelete();
            $table->foreign('factoring_company_id', 'chq_pay_factoring_co_fk')
                  ->references('id')->on('factoring_companies');

            $table->index(['company_id', 'reverse_factoring_transaction_id'], 'chq_pay_company_rev_factoring_idx');
        });

        // partner_id was NOT NULL — loosen it for the reverse-factoring
        // source only (payee is a Factoring Company there, never a
        // Partner). Raw SQL, not ->change() — that needs doctrine/dbal,
        // which this project doesn't necessarily have installed.
        DB::statement('ALTER TABLE cheques_payable MODIFY partner_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropForeign('chq_pay_rev_factoring_fk');
            $table->dropForeign('chq_pay_factoring_co_fk');
            $table->dropIndex('chq_pay_company_rev_factoring_idx');
            $table->dropColumn(['reverse_factoring_transaction_id', 'factoring_company_id']);
        });
        DB::statement('ALTER TABLE cheques_payable MODIFY partner_id BIGINT UNSIGNED NOT NULL');
    }
};
