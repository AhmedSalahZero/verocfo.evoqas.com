<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000136_add_payment_tracking_to_supplier_bills
//
//  Supplier Payment (August 2026) — supplier_bills never got the
//  amount_paid/amount_outstanding pair customer_invoices has always
//  had. Without these, Supplier Payment's Settlement Information
//  table (mirroring Customer Collection's own) has nothing to read a
//  bill's Net Balance from, and nothing to update when a payment
//  settles it.
//
//  Backfilled from bill_value_functional so every existing posted
//  bill starts fully outstanding, matching how a Supplier Bill has
//  never had any payment recorded against it in this app until now.
//
//  @layer    Migration
//  @depends  supplier_bills
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->default(0)->after('bill_value_functional');
            $table->decimal('amount_outstanding', 15, 2)->default(0)->after('amount_paid');
        });

        // Backfill: outstanding must be the NET AP amount (post-WHT),
        // matching exactly what SupplierBillService::post() actually
        // credited to Accounts Payable — bill_value_functional minus
        // whatever was withheld (that portion went to 226002
        // Withholding Tax Payable instead, a real liability to the tax
        // authority, never money owed to the supplier). Using the
        // gross bill value here would overstate what Supplier Payment
        // thinks is still owed on every bill that had WHT withheld.
        DB::statement('
            UPDATE supplier_bills
            SET amount_outstanding = bill_value_functional - withholding_tax_amount_functional
            WHERE status = "posted"
        ');

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'amount_outstanding'], 'sb_company_status_outstanding');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropIndex('sb_company_status_outstanding');
            $table->dropColumn(['amount_paid', 'amount_outstanding']);
        });
    }
};
