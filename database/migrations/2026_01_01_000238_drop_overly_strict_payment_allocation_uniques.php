<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_09_04_000002_drop_overly_strict_payment_allocation_uniques
//
//  supplier_payment_allocations and customer_payment_allocations both
//  had a unique constraint on (payment_id, bill/invoice_id) — meaning
//  a single payment could only ever be applied to a given bill or
//  invoice ONCE, for the rest of that payment's life. That's wrong:
//  applying part of a payment to a bill today and more of the SAME
//  unapplied payment to the SAME bill later (a perfectly normal
//  partial-then-partial workflow — see Screenshot from Mahmoud,
//  "Apply Payment" on SP/2026/09/00018) is a legitimate, real action,
//  and allocate() in both services already creates a brand-new row
//  with its own journal entry every time it's called — it was never
//  written to expect at-most-one row per pair. Confirmed nothing
//  else in the codebase queries or relies on that uniqueness
//  (SupplierPaymentService/SupplierBillService only ever filter by
//  payment_id alone, never the pair) — the constraint itself was the
//  bug, not the create() logic underneath it.
//
//  @layer    Migration
//  @depends  supplier_payment_allocations, customer_payment_allocations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Order matters: MySQL/InnoDB requires SOME index covering a
        // foreign-keyed column at all times. supplier_payment_id and
        // customer_payment_id's FKs were only being supported by the
        // unique index being dropped here (the other indexes on this
        // table have company_id leading, so they don't count) — the
        // replacement index has to exist BEFORE the old one is
        // dropped, or MySQL refuses the drop with error 1553.
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->index(['supplier_payment_id', 'supplier_bill_id'], 'spa_payment_bill_index');
        });
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->dropUnique('spa_payment_bill_unique');
        });

        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->index(['customer_payment_id', 'customer_invoice_id'], 'cpa_payment_invoice_index');
        });
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->dropUnique('cpa_payment_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->unique(['supplier_payment_id', 'supplier_bill_id'], 'spa_payment_bill_unique');
        });
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->dropIndex('spa_payment_bill_index');
        });

        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->unique(['customer_payment_id', 'customer_invoice_id'], 'cpa_payment_invoice_unique');
        });
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->dropIndex('cpa_payment_invoice_index');
        });
    }
};