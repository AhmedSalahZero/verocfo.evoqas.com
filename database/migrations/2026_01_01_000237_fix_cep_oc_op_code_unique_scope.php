<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_09_04_000001_fix_cep_oc_op_code_unique_scope
//
//  Same bug, same fix as 2026_01_01_000150 (Purchase Orders),
//  2026_01_01_000151 (Sales Orders), 2026_01_01_000156 (Goods
//  Receipt Notes), and 2026_01_01_000162 (Customer Invoices /
//  Supplier Bills) — just never applied to these three. Found while
//  seeding two demo companies back to back: company A's first Cash
//  Expense Payment of the month correctly computes CEP/YYYY/MM/00001
//  for itself; company B's first Cash Expense Payment of the same
//  month ALSO correctly computes CEP/YYYY/MM/00001 for itself — but
//  the old global-unique rule wrongly rejected company B's as a
//  duplicate of company A's, even though CashExpensePaymentService/
//  OtherCollectionService/OtherPaymentService's own generate*Code()
//  methods already count existing rows scoped to company_id (i.e.
//  the application logic already assumed per-company uniqueness —
//  only the database constraint disagreed).
//
//  @layer    Migration
//  @depends  cash_expense_payments, other_collections, other_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expense_payments', function (Blueprint $table) {
            $table->dropUnique('cash_expense_payments_payment_code_unique');
            $table->unique(['company_id', 'payment_code']);
        });

        Schema::table('other_collections', function (Blueprint $table) {
            $table->dropUnique('other_collections_collection_code_unique');
            $table->unique(['company_id', 'collection_code']);
        });

        Schema::table('other_payments', function (Blueprint $table) {
            $table->dropUnique('other_payments_payment_code_unique');
            $table->unique(['company_id', 'payment_code']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_expense_payments', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'payment_code']);
            $table->unique('payment_code');
        });

        Schema::table('other_collections', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'collection_code']);
            $table->unique('collection_code');
        });

        Schema::table('other_payments', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'payment_code']);
            $table->unique('payment_code');
        });
    }
};
