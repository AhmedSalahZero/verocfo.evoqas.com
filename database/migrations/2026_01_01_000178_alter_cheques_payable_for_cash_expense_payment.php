<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000178_alter_cheques_payable_for_cash_expense_payment
//
//  Per Mahmoud: a cheque issued through Cash Expense Payment is not a
//  separate list — one shared Payable Cheques screen for all three
//  sources. Same widening migration 000175 already did for Other
//  Payment, applied a second time:
//
//    cash_expense_payment_id → new, nullable, unique (same 1:1 shape
//    as supplier_payment_id / other_payment_id)
//
//  Exactly ONE of the three parent FKs must be set on any given row —
//  enforced in ChequePayableService, not the schema (unchanged
//  reasoning from 000175 / the original 000139).
//
//  The source account still lives on WHICHEVER parent issued the
//  cheque — never duplicated here.
//
//  @layer    Migration
//  @depends  cheques_payable, cash_expense_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->foreignId('cash_expense_payment_id')->nullable()->after('other_payment_id')
                ->constrained('cash_expense_payments')->cascadeOnDelete();
        });

        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->unique('cash_expense_payment_id', 'cheq_pay_cash_expense_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropUnique('cheq_pay_cash_expense_payment_unique');
            $table->dropForeign(['cash_expense_payment_id']);
            $table->dropColumn('cash_expense_payment_id');
        });
    }
};
