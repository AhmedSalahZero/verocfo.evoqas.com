<?php

// ══════════════════════════════════════════════════════════════════
//  alter_supplier_bills_add_withholding_tax
//
//  Adds Withholding Tax (the tax YOUR company deducts when paying a
//  SUPPLIER, remitted to the tax authority on their behalf — a
//  liability, credited to 226002 Withholding Tax Payable) to Supplier
//  Bills. Confirmed with Mahmoud (July 2026): entered directly on the
//  bill itself, NOT resolved automatically from Tax Settings the way
//  VAT is — the user types the rate on each bill. This is the
//  opposite direction from the existing "Debit WHT" (what a CUSTOMER
//  withholds from what they pay YOU — a receivable, unrelated to
//  this column).
//
//  Calculated on the subtotal BEFORE VAT (confirmed) — never on the
//  VAT-inclusive total.
//
//  @layer    Migration
//  @depends  supplier_bills
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->decimal('withholding_tax_rate', 5, 2)->default(0)->after('vat_amount_functional');
            $table->decimal('withholding_tax_amount', 15, 2)->default(0)->after('withholding_tax_rate');
            $table->decimal('withholding_tax_amount_functional', 15, 2)->default(0)->after('withholding_tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn(['withholding_tax_rate', 'withholding_tax_amount', 'withholding_tax_amount_functional']);
        });
    }
};
