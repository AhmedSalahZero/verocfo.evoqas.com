<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000123_create_customer_payment_allocations_table
//
//  The matching layer between Customer Payments and Customer
//  Invoices — genuinely many-to-many: one payment can be spread
//  across several open invoices (a customer clears their whole
//  account in one transfer), and one invoice can be paid off over
//  several separate payments (partial payment terms). Neither
//  Customer Payment nor Customer Invoice references the other
//  directly — this table is the only place the relationship exists.
//
//  Allocating does NOT post its own journal entry — the cash was
//  already recognized in full when the payment itself posted (Dr
//  Bank / Cr AR for the whole amount). An allocation only moves which
//  invoice(s)' amount_outstanding the existing AR credit is attributed
//  against — it's a matching/reporting operation on top of a GL
//  entry that already exists, not a new financial transaction.
//
//  allocated_amount can never exceed the lesser of: the payment's
//  remaining unallocated_amount, or the invoice's remaining
//  amount_outstanding at the moment of allocation — enforced in
//  CustomerPaymentService, not at the database level (both sides are
//  running totals on their own tables, not something a DB constraint
//  can check across three tables at once).
//
//  Append-style: allocations are created when the user matches a
//  payment to invoice(s), and can be removed (un-matched) later,
//  which reverses both running totals — but removing an allocation
//  never touches the original payment's journal entry, since the
//  cash itself was never in question, only which invoice it applied
//  to.
//
//  @layer    Migration
//  @depends  customer_payments, customer_invoices, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('customer_payment_id')->constrained('customer_payments')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices');

            $table->decimal('allocated_amount', 15, 2); // functional currency

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'customer_payment_id'], 'cpa_company_payment_idx');
            $table->index(['company_id', 'customer_invoice_id'], 'cpa_company_invoice_idx');
            // A given payment should only carry one allocation row per invoice —
            // adjust the existing row's amount rather than creating a second one.
            $table->unique(['customer_payment_id', 'customer_invoice_id'], 'cpa_payment_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
    }
};
