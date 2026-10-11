<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000212_alter_supplier_bills_add_due_dates
//
//  Payable-side mirror of migration 000209 (customer_invoices). Same
//  two-field design: original_due_date is set ONCE, at posting time,
//  computed from the SUPPLIER's own Payment Term
//  (Partner::payment_term_id — the supplier-side equivalent of
//  collection_term_id) and never changed again. due_date starts
//  identical but can be moved later via "Adjust Due Date" — see
//  SupplierBillService::adjustDueDate().
//
//  @layer    Migration
//  @depends  supplier_bills
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->date('original_due_date')->nullable()->after('bill_date');
            $table->date('due_date')->nullable()->after('original_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn(['original_due_date', 'due_date']);
        });
    }
};
