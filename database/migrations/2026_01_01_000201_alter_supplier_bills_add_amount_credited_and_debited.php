<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000201_alter_supplier_bills_add_amount_credited_and_debited
//
//  Purchase Cycle — Supplier Debit Note / Supplier Credit Note.
//  supplier_bills never had either column — it only had amount_paid
//  (migration 000136). Mirrors customer_invoices' amount_credited
//  (migration 000124) / amount_debited (migration 000196) pair,
//  applied to the payable side:
//
//    amount_credited — reduced by a Supplier Debit Note (we're
//                      claiming a return/overcharge, AP goes down)
//    amount_debited  — increased by a Supplier Credit Note (the
//                      supplier charges us more after the bill, AP
//                      goes up)
//
//  amount_outstanding is now maintained as:
//    bill_value_functional - amount_paid - amount_credited + amount_debited
//  by SupplierBillService (post), the new SupplierDebitNoteService
//  (post, decrements amount_credited... — reads: increments
//  amount_credited, which reduces amount_outstanding), and the new
//  SupplierCreditNoteService (post, increments amount_debited, which
//  increases amount_outstanding). Each service owns exactly one term
//  in this formula and updates amount_outstanding in the same
//  transaction as its own journal entry — same pattern already
//  established on the customer_invoices side.
//
//  @layer    Migration
//  @depends  supplier_bills
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->decimal('amount_credited', 15, 2)->default(0)->after('amount_paid');
            $table->decimal('amount_debited', 15, 2)->default(0)->after('amount_credited');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn(['amount_credited', 'amount_debited']);
        });
    }
};
