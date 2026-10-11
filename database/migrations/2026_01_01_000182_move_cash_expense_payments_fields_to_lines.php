<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000182_move_cash_expense_payments_fields_to_lines
//
//  Companion to migration 000181 — Cash Expense Payment became a
//  multi-line document, so these per-expense fields move down to
//  cash_expense_payment_lines and are dropped here from the parent:
//    expense_account_id, cost_center_id, vat_rate, withholding_tax_rate
//  (vat_rate/withholding_tax_rate never meant anything at payment
//  level once more than one line can each carry a different rate).
//
//  KEPT on the parent, unchanged: subtotal, vat_amount,
//  withholding_tax_amount, net_amount, and their _functional twins —
//  these become AGGREGATE TOTALS (sum of every line, recalculated on
//  every save by CashExpensePaymentService), purely for fast list-
//  page display. Same convention Supplier Bill already uses for its
//  own bill-level totals sitting alongside its line-level detail.
//
//  @layer    Migration
//  @depends  cash_expense_payments, cash_expense_payment_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expense_payments', function (Blueprint $table) {
            $table->dropForeign(['expense_account_id']);
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn(['expense_account_id', 'cost_center_id', 'vat_rate', 'withholding_tax_rate']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_expense_payments', function (Blueprint $table) {
            $table->foreignId('expense_account_id')->nullable()->after('payee_description')
                ->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()->after('expense_account_id')
                ->constrained('cost_centers')->nullOnDelete();
            $table->decimal('vat_rate', 5, 2)->default(0)->after('vat_amount');
            $table->decimal('withholding_tax_rate', 5, 2)->default(0)->after('vat_rate');
        });
    }
};
