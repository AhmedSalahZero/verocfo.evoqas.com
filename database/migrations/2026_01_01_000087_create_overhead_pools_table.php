<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000087_create_overhead_pools_table
//
//  Mass Production — Layer 2, piece 2 of 5. Confirmed with Mahmoud:
//  a company defines as many overhead pools as it wants (e.g.
//  "Factory Rent," "Utilities," "Machine Depreciation"), each pool
//  picks ONE of exactly 3 allocation bases. Not a fixed count of
//  pools — the allocation base enum is the only fixed vocabulary.
//
//  A pool's rate lives in standard_rates (migration 000088), not
//  here — this table is just the pool's identity and allocation
//  base; the rate is what changes annually/monthly.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overhead_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->enum('allocation_base', ['labor_hours', 'machine_hours', 'production_units']);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overhead_pools');
    }
};
