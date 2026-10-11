<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000124_alter_customer_invoices_add_amount_credited
//
//  Sales Cycle — Credit Note (module 2), built alongside Customer
//  Invoice and Customer Payment in the same push. Found while
//  designing CreditNoteService: migration 000118 only gave
//  CustomerInvoice amount_paid (cash) and amount_outstanding — with
//  no way to record that some of an invoice's reduction came from a
//  Credit Note rather than a cash payment. Reusing amount_paid for
//  credits would be semantically wrong (a credit isn't cash) and
//  would make a future AR aging / collections report unable to tell
//  the two apart.
//
//  amount_outstanding is now maintained as:
//    invoice_value_functional - amount_paid - amount_credited
//  by both CustomerInvoiceService (post) and CreditNoteService
//  (post) — CreditNoteService increments amount_credited and reduces
//  amount_outstanding in the same transaction as its journal entry;
//  CustomerPaymentService's allocation logic does the equivalent for
//  amount_paid.
//
//  @layer    Migration
//  @depends  customer_invoices
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->decimal('amount_credited', 15, 2)->default(0)->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_credited');
        });
    }
};
