<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000082_create_stock_movements_table
//
//  Inventory Engine — Layer 1. The permanent, append-only ledger of
//  every physical stock event in the system. This is the source of
//  truth every other inventory table (product_stock_balances,
//  product_location_stock) is derived from and can always be
//  rebuilt against.
//
//  DUAL-VALUATION RULE (confirmed with Mahmoud — non-negotiable,
//  same weight as Building Bible Rule 31):
//  Vero CFO has exactly ONE official cost basis for the company's
//  books — Weighted Average Cost. This never changes, even for
//  System Integrator / project-based companies. wac_unit_cost /
//  wac_total_value are ALWAYS populated on every row and are the
//  only figures that ever post to the GL.
//
//  actual_unit_cost / actual_total_value are a SEPARATE, parallel
//  figure — populated ONLY when project_id is set, sourced from the
//  specific purchase line that item came from. This feeds the
//  Project P&L and Project Inventory Statement ONLY. It is a
//  reporting dimension riding alongside the same physical event —
//  it is never a journal entry and never touches the Income
//  Statement or Balance Sheet. The two values are expected to
//  differ and neither corrects the other.
//
//  WAC SCOPE (confirmed): one WAC per product PER COMPANY — not
//  per location, not per project. inventory_location_id on this
//  table is for QUANTITY tracking only (which warehouse/site
//  physically holds the item) — it never carries its own cost.
//  See product_stock_balances (company-wide cost) vs
//  product_location_stock (per-location quantity only).
//
//  reference_type / reference_id is a polymorphic pointer back to
//  whatever business document caused this movement — Purchase
//  Order/GRN, Sales Delivery Note, Production Order (Mass
//  Production), Job Order, Manual Adjustment, or Opening Balance.
//  Those documents don't exist yet (Accounting Engine Phase 1/2 and
//  the Manufacturing/Job Order layers are still to come) — this
//  column is ready for them so no second migration is needed later,
//  same pattern as purchase_order_lines.received_quantity.
//
//  movement_type is deliberately broad now (covers Layer 2/3 values
//  like production_consumption, production_receipt, job_consumption,
//  job_receipt) even though nothing writes those yet — reserved so
//  Mass Production and Job Order don't need a column change later.
//
//  @layer    Migration
//  @depends  companies, products, inventory_locations, projects
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->enum('movement_type', [
                'receipt',                 // goods received into stock (PO/GRN, opening balance, etc.)
                'issue',                   // stock issued out (sales delivery, general consumption)
                'transfer_out',
                'transfer_in',
                'adjustment_in',
                'adjustment_out',
                'production_consumption',  // Mass Production — raw material consumed into a production order
                'production_receipt',      // Mass Production — finished goods received from a production order
                'job_consumption',         // Job Order — raw material consumed into a specific job
                'job_receipt',             // Job Order — finished goods received from a specific job
                'opening_balance',
            ]);

            $table->decimal('quantity', 15, 4);

            // ── GL-facing value — ALWAYS populated, the only figure that ever posts ──
            $table->decimal('wac_unit_cost', 15, 4);
            $table->decimal('wac_total_value', 15, 2);

            // ── Project-facing value — nullable, populated only when project_id is set ──
            // Never posted to GL. See header for the dual-valuation rule.
            $table->decimal('actual_unit_cost', 15, 4)->nullable();
            $table->decimal('actual_total_value', 15, 2)->nullable();

            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            // ── Polymorphic pointer to the source document ──
            $table->nullableMorphs('reference');

            $table->date('movement_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'inventory_location_id']);
            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
