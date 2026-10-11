<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000192_add_prepaid_bill_type_and_amortization_months
//
//  Prepaid Expense Bill — the third New Supplier Bill tile, alongside
//  Goods Bill and Expense Bill. Confirmed by Mahmoud: identical to
//  Expense Bill in every respect (same VAT/WHT treatment, same
//  immediate-posting behavior) except one thing — each line carries
//  its own Amortization Months, and the line debits 136 Prepaid
//  Expenses (an already-seeded, previously-unused system account —
//  see ChartOfAccountSeeder) instead of the line's own expense
//  account. The chosen expense account is NOT discarded — it's where
//  PrepaidAmortizationService posts each period's amortization to,
//  read from this same chart_of_account_id column. See
//  PrepaidAmortizationService header for the full mechanism,
//  including the confirmed mid-month convention (Bill Date day <= 15
//  = the bill's own month counts as a full period; day >= 16 = a
//  half-period at each end of the schedule).
//
//  MySQL enum columns require every value listed up front — rebuilding
//  the column, same pattern as migration 000189.
//
//  amortization_months is nullable at the schema level (goods/expense
//  lines never set it) — SupplierBillRequest enforces it's present
//  and >= 1 only when the bill's own bill_type is 'prepaid'.
//
//  @layer    Migration
//  @depends  supplier_bills, supplier_bill_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE supplier_bills MODIFY bill_type ENUM('goods', 'expense', 'prepaid') NOT NULL");

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('amortization_months')->nullable()->after('chart_of_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropColumn('amortization_months');
        });

        DB::statement("ALTER TABLE supplier_bills MODIFY bill_type ENUM('goods', 'expense') NOT NULL");
    }
};
