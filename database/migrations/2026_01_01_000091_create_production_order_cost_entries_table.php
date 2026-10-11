<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000091_create_production_order_cost_entries_table
//
//  Mass Production — Layer 2, piece 5 of 5. Progressive labor and
//  overhead cost postings against an open Production Order —
//  confirmed with Mahmoud: WIP carries a running balance as the
//  order progresses, not just a lump sum at completion.
//
//  WHY THIS IS A SEPARATE TABLE FROM stock_movements:
//  Material consumption fits stock_movements perfectly — it has a
//  real product, quantity, and location physically moving. Labor
//  and overhead don't — there's no product or quantity changing
//  hands, only cost being accrued against the job. Forcing a labor
//  posting into stock_movements would mean a fake row with no real
//  quantity, which would corrupt what's supposed to be a clean
//  physical ledger (and would break product_stock_balances /
//  product_location_stock, which assume every stock_movements row
//  represents an actual product quantity change). Same "keep
//  structures separate for clarity" principle already applied to
//  keeping Job Order separate from Project and from Mass Production
//  itself.
//
//  A Production Order's running WIP value at any moment =
//    SUM(stock_movements.wac_total_value WHERE reference = this
//        order AND movement_type = production_consumption)
//    + SUM(production_order_cost_entries.amount WHERE
//          production_order_id = this order)
//
//  entry_type distinguishes labor from overhead; overhead_pool_id is
//  set only for overhead entries (which pool it came from — a single
//  order can accrue against multiple pools over its life).
//
//  standard_amount / actual_amount are both stored on each entry so
//  month-end variance can be computed per entry, not just at the
//  order level — actual_amount is what really happened (e.g. actual
//  hours logged this week × standard rate, or an actual overhead
//  allocation posting); standard_amount is what the BOM + standard
//  rate said that same slice of work should have cost.
//
//  @layer    Migration
//  @depends  production_orders, overhead_pools, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();

            $table->enum('entry_type', ['labor', 'overhead']);
            $table->foreignId('overhead_pool_id')->nullable()->constrained('overhead_pools')->restrictOnDelete();

            // The allocation-base quantity this entry represents — labor
            // hours logged, or machine hours / production units for an
            // overhead entry, depending on that pool's allocation_base.
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('rate_applied', 15, 4);      // the standard_rates rate in effect when this entry was posted
            $table->decimal('standard_amount', 15, 2);   // base_quantity × rate_applied — what it SHOULD cost per standard
            $table->decimal('actual_amount', 15, 2);     // what actually accrued — feeds variance alongside standard_amount

            $table->date('entry_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'production_order_id'], 'poce_company_production_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_cost_entries');
    }
};
