<?php

// ══════════════════════════════════════════════════════════════════
//  alter_supplier_bill_withholding_tax_to_line_level
//
//  Confirmed with Mahmoud (July 2026, follow-up): Withholding Tax
//  moves from one rate per BILL to one rate per LINE — a single bill
//  can carry both goods lines and service/expense lines, and those
//  legitimately carry different withholding rates in practice. Same
//  reasoning already applied to VAT, which is per-line for the exact
//  same reason.
//
//  `supplier_bills.withholding_tax_rate` (added in the previous
//  migration, alter_supplier_bills_add_withholding_tax) is dropped —
//  a single bill-level rate no longer means anything once each line
//  can differ. `withholding_tax_amount` / `_functional` on the bill
//  STAY — they now represent the SUM of every line's own withheld
//  amount (same relationship subtotal_amount already has to each
//  line's line_subtotal), used for the Net Payable calculation and
//  the GL posting split. Per Rule 5 (Migration Discipline): never
//  modify an existing migration — this is a new one, not an edit of
//  alter_supplier_bills_add_withholding_tax.
//
//  @layer    Migration
//  @depends  supplier_bills, supplier_bill_lines
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn('withholding_tax_rate');
        });

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->decimal('withholding_tax_rate', 5, 2)->default(0)->after('vat_amount');
            $table->decimal('withholding_tax_amount', 15, 2)->default(0)->after('withholding_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropColumn(['withholding_tax_rate', 'withholding_tax_amount']);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->decimal('withholding_tax_rate', 5, 2)->default(0)->after('vat_amount_functional');
        });
    }
};
