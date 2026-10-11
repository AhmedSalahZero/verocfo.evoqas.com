<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000107_create_supplier_bill_lines_table
//
//  A line is EXACTLY ONE of three shapes — validated by
//  SupplierBillService, not the database:
//
//    1. GOODS LINE      — goods_receipt_note_line_id set.
//                          chart_of_account_id / accrual_id both null.
//    2. FRESH EXPENSE   — chart_of_account_id set (user-picked
//                          expense/asset account). goods_receipt_note_
//                          line_id / accrual_id both null.
//    3. ACCRUAL SETTLE  — accrual_id set. The debit account is NOT
//                          user-picked here — it is always 228
//                          Accrued Expenses, resolved by the service.
//                          chart_of_account_id / goods_receipt_note_
//                          line_id both null.
//
//  PRICE VARIANCE (goods lines only — confirmed by Mahmoud):
//  `unit_price` is the ACTUAL invoice price, which may differ from
//  the matched GRN line's unit_cost. The difference × quantity is
//  `variance_amount` (signed). Rather than a dedicated variance
//  account, the user chooses how to split it between the product's
//  own inventory account and its COGS account via
//  `variance_inventory_pct` (0–100, default 100 = fully adjusts
//  inventory — Mahmoud's stated default, since goods are frequently
//  still partly in stock when the bill lands). The remaining
//  (100 - pct)% posts to the product's cogs_account_id. Every
//  matched PO line's has_price_variance / price_variance_amount
//  (migration 000103) is updated from this value when the bill posts.
//
//  VAT is captured per line (not just at document level) because
//  goods and expense lines can carry different rates on the same
//  bill. `vat_rate` prefills from TaxService::resolveVatRate() for
//  goods lines (product-based) or the company default for expense
//  lines (no product to resolve from) — always user-overridable.
//
//  Project/SO tagging on an expense line is the Project Module's own
//  documented rule (§7.2: "Services → actual amounts on supplier
//  bills tagged to project") — NOT available on a goods line, which
//  is already tagged at the GRN line level and would be redundant/
//  conflicting to re-tag here.
//
//  @layer    Migration
//  @depends  supplier_bills, goods_receipt_note_lines, accruals,
//            chart_of_accounts, products, units_of_measure,
//            cost_centers, projects, sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();

            // ── Exactly one of these three is set — see header ──
            $table->foreignId('goods_receipt_note_line_id')->nullable()
                ->constrained('goods_receipt_note_lines')->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete(); // fresh expense/asset line only
            $table->foreignId('accrual_id')->nullable()
                ->constrained('accruals')->nullOnDelete();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete(); // goods lines only
            $table->string('description', 500);

            $table->decimal('quantity', 15, 4)->default(1); // expense lines default to 1 — no physical quantity
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 15, 2); // bill currency — actual invoice price
            $table->decimal('line_subtotal', 15, 2); // bill currency, excl VAT — qty x unit_price

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0); // bill currency
            $table->decimal('line_total', 15, 2); // bill currency — subtotal + vat

            // ── Price variance — goods lines only, see header ──
            $table->decimal('variance_amount', 15, 2)->default(0); // functional currency, signed
            $table->decimal('variance_inventory_pct', 5, 2)->default(100); // 0-100, default 100 per Mahmoud

            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete(); // expense lines only
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete(); // expense lines only

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'supplier_bill_id']);
            $table->index(['company_id', 'goods_receipt_note_line_id'], 'sbl_company_grn_line_idx'); // explicitly named — default auto-generated name (63/64 chars) left no safety margin
            $table->index(['company_id', 'accrual_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_lines');
    }
};
