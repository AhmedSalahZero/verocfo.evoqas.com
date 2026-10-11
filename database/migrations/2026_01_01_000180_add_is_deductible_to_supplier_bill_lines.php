<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000180_add_is_deductible_to_supplier_bill_lines
//
//  "Is Deductible" VAT (August 2026, per Mahmoud) — confirmed
//  per-LINE, not per-bill: one bill can mix a deductible goods line
//  with a non-deductible expense line. Default TRUE (today's existing
//  behavior, unchanged for every line that doesn't touch this).
//
//  When TRUE (default): unchanged — the line's vat_amount posts to
//  137 VAT Input (recoverable asset), exactly as before this change.
//
//  When FALSE: the VAT is NOT recoverable, so it stops being a
//  separate asset and instead folds into the line's own cost —
//  confirmed with Mahmoud per line type:
//    - Fresh Expense line   → folds into the line's own
//                             chart_of_account_id (same account its
//                             subtotal already debits)
//    - Accrual Settlement   → folds into 228 Accrued Expenses (same
//                             account its subtotal already debits)
//    - Goods/Inventory line → folds into the GRNI/Inventory side,
//                             using the EXACT SAME split mechanism as
//                             price variance (variance_inventory_pct
//                             between the product's inventory_account_id
//                             and cogs_account_id) — confirmed with
//                             Mahmoud: "affects Weighted Average Cost".
//                             A Fixed Asset goods line folds the full
//                             amount into fixed_asset_account_id
//                             instead (no split — same reasoning
//                             SupplierBillService::postFixedAssetVariance()
//                             already uses for price variance: one
//                             specific identifiable asset, never a
//                             COGS split).
//
//  Withholding Tax is UNCHANGED by this flag either way — it was
//  already computed on line_subtotal (before VAT) and stays that way
//  regardless of deductibility (confirmed with Mahmoud).
//
//  KNOWN LIMITATION (documented, not silently glossed over): folding
//  non-deductible VAT into a product's inventory_account_id changes
//  that GL account's balance, but does NOT recalculate the per-unit
//  Weighted Average Cost in the separate stock_movements ledger
//  (StockLedgerService) — same class of gap already documented for
//  price variance / actual_cost in
//  VeroCFO_Project_Module_Structure_v1_1.md §6.4. If per-unit WAC must
//  reflect non-deductible VAT too, that is a StockLedgerService change
//  for a future session, not something this migration/service change
//  attempts.
//
//  @layer    Migration
//  @depends  supplier_bill_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->boolean('is_deductible')->default(true)->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropColumn('is_deductible');
        });
    }
};
