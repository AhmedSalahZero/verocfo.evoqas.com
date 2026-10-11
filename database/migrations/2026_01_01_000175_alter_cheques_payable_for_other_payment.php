<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000175_alter_cheques_payable_for_other_payment
//
//  Per Mahmoud (same instruction as Other Collection's cheques):
//  a cheque issued through Other Payment is not a separate list from
//  cheques issued to suppliers — one shared screen. Widens
//  cheques_payable exactly the way migration 000173 widened
//  cheques_receivable:
//    supplier_payment_id → now nullable (was mandatory)
//    other_payment_id    → new, nullable, unique (same 1:1 shape)
//  Exactly one of the two must be set — enforced in
//  ChequePayableService, not the schema (same reasoning as 000173).
//
//  The source account (source_bank_account_id / source_credit_
//  facility_id) lives on WHICHEVER parent issued the cheque — never
//  duplicated on this table (unchanged design principle from the
//  original migration 000139) — so ChequePayableService must resolve
//  it from whichever parent is actually set.
//
//  @layer    Migration
//  @depends  cheques_payable, other_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropForeign(['supplier_payment_id']);
            $table->dropUnique(['supplier_payment_id']);
        });

        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->foreignId('supplier_payment_id')->nullable()->change();
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->cascadeOnDelete();

            $table->foreignId('other_payment_id')->nullable()->after('supplier_payment_id')
                ->constrained('other_payments')->cascadeOnDelete();
        });

        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->unique('supplier_payment_id', 'cheq_pay_supplier_payment_unique');
            $table->unique('other_payment_id', 'cheq_pay_other_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropUnique('cheq_pay_supplier_payment_unique');
            $table->dropUnique('cheq_pay_other_payment_unique');
            $table->dropForeign(['other_payment_id']);
            $table->dropColumn('other_payment_id');

            $table->dropForeign(['supplier_payment_id']);
            $table->foreignId('supplier_payment_id')->nullable(false)->change();
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->cascadeOnDelete();
            $table->unique('supplier_payment_id');
        });
    }
};
