<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000263_create_machine_overhead_pools_table
//
//  Machine ↔ Overhead Pool link (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 4.4). Confirmed with Mahmoud (Option A,
//  Sept 2026): a genuine many-to-many — one Machine can feed several
//  Overhead Pools (a machine with both a maintenance-contract pool
//  and a shared electricity pool), AND one Overhead Pool can be fed
//  by several Machines (one shared "Factory Electricity" pool spread
//  across many machines, reconciled against one real electricity
//  bill). Neither side is forced into a 1:1 shape.
//
//  Modeled as a real Eloquent model (MachineOverheadPool) with
//  BelongsToCompany, not a bare pivot table with no model — same
//  pattern already established for company_active_currencies
//  (migration 000028), this codebase's existing precedent for a
//  many-to-many link table. Rule 2.2 (every model uses BelongsToCompany,
//  no exceptions) applies here too.
//
//  @layer    Migration
//  @depends  companies, machines, overhead_pools
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_overhead_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('overhead_pool_id')->constrained('overhead_pools')->cascadeOnDelete();

            $table->timestamps();

            // One link per Machine/Pool pair — re-linking the same
            // pair would just be a silent duplicate.
            $table->unique(['machine_id', 'overhead_pool_id'], 'mop_machine_pool_unique');
            $table->index(['company_id', 'machine_id']);
            $table->index(['company_id', 'overhead_pool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_overhead_pools');
    }
};
