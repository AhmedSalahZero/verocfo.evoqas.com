<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000196_alter_customer_invoices_add_amount_debited
//
//  Sales Cycle — Customer Debit Note. Mirrors migration 000124's
//  amount_credited addition exactly, opposite direction: amount_paid
//  and amount_credited both REDUCE an invoice's outstanding balance;
//  amount_debited INCREASES it.
//
//  amount_outstanding is now maintained as:
//    invoice_value_functional + amount_debited - amount_paid - amount_credited
//  by CustomerInvoiceService (post), CreditNoteService (post), and the
//  new CustomerDebitNoteService (post) — each owns exactly one term in
//  this formula and updates amount_outstanding in the same transaction
//  as its own journal entry, same pattern already established for
//  amount_credited.
//
//  @layer    Migration
//  @depends  customer_invoices
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->decimal('amount_debited', 15, 2)->default(0)->after('amount_credited');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_debited');
        });
    }
};
