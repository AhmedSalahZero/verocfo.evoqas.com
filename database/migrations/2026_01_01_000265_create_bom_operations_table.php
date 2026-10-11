<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000265_create_bom_operations_table
//
//  BOM Routing — the ordered list of Operations belonging to a BOM
//  (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md, Section 5).
//  Replaces the BOM's old two flat numbers (dropped in migration
//  000264) with a proper per-operation breakdown: Sequence, Process,
//  a default Machine (for standard costing), a Labor Category, and
//  how many labor/machine hours that step needs per batch.
//
//  Confirmed with Mahmoud, Sept 2026: an operation's overhead cost is
//  the SUM across every Overhead Pool linked to its default_machine_id
//  (Section 4.4, Option A — a Machine can feed several pools at once,
//  e.g. its own maintenance contract plus a shared factory-electricity
//  pool). This table does NOT carry its own overhead_pool_id — the
//  machine already owns that many-to-many link (machine_overhead_pools,
//  migration 000263); duplicating it here would let an operation's
//  pools drift out of sync with its machine's actual pools. See
//  ProductionOrderService::standardOverheadCostPerUnit() for the
//  calculation that reads through default_machine_id to
//  machine_overhead_pools.
//
//  All three identity FKs (process_id, default_machine_id,
//  labor_category_id) use restrictOnDelete — an Operation is live
//  costing configuration on a real BOM, so silently cascading a
//  delete through it would corrupt standard cost without any visible
//  trace. In practice none of Process/Machine/LaborCategory have a
//  delete path in this app anyway (create/edit + toggle only, same
//  as OverheadPool) — this is defensive, not expected to ever fire.
//
//  sequence is the operation's order within its BOM (Cutting → Welding
//  → Packing) — set from the array position on save, same pattern as
//  BomLine.sort_order.
//
//  Both hour fields are nullable-equivalent via default(0), NOT
//  ->nullable() (Rule 5 — never nullable on a financial-amount-style
//  column) — an operation can legitimately be pure-labor (0 machine
//  hours) or pure-machine/automated (0 labor hours), same flexibility
//  the old flat BOM fields had.
//
//  @layer    Migration
//  @depends  bill_of_materials, processes, machines, labor_categories
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('bill_of_material_id')->constrained('bill_of_materials')->cascadeOnDelete();
            $table->unsignedInteger('sequence');

            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete();
            $table->foreignId('default_machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('labor_category_id')->constrained('labor_categories')->restrictOnDelete();

            $table->decimal('standard_labor_hours', 15, 4)->default(0);
            $table->decimal('standard_machine_hours', 15, 4)->default(0);

            $table->timestamps();

            $table->unique(['bill_of_material_id', 'sequence'], 'bom_op_bom_sequence_unique');
            $table->index(['company_id', 'bill_of_material_id']);
            $table->index(['company_id', 'default_machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_operations');
    }
};
