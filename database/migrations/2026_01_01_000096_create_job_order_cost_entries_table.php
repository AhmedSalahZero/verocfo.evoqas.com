<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000096_create_job_order_cost_entries_table
//
//  Job Order Production — piece 5 of 5. Progressive labor and
//  overhead cost postings against an open Job Order — same
//  reasoning as production_order_cost_entries (migration 000091)
//  for why this is a separate table from stock_movements: no
//  product/quantity physically changing hands, just cost accruing.
//
//  A DELIBERATE, SEPARATE TABLE FROM production_order_cost_entries
//  — not a shared/polymorphic table — per Mahmoud's explicit
//  instruction to keep Job Order structurally apart from Mass
//  Production for clarity in fixing and debugging.
//
//  THE ONE DELIBERATE POINT OF REUSE (confirmed): overhead_pool_id
//  points at the SAME overhead_pools table Mass Production uses, and
//  rate_applied is resolved from the SAME standard_rates mechanism
//  — "overhead reuses Mass Production's existing pools and standard
//  rates, applied per job instead of per period." Nothing else about
//  Job Order reuses Mass Production's tables.
//
//  No standard_amount column here (unlike
//  production_order_cost_entries) — Job Order has no per-entry
//  "standard" to compute against. The single comparison point is
//  job_orders.estimated_labor_cost_per_unit /
//  estimated_overhead_cost_per_unit, captured once at job opening —
//  not recalculated per entry the way Mass Production's standard
//  cost is. actual_amount here is compared against that one
//  job-level estimate, not entry-by-entry.
//
//  @layer    Migration
//  @depends  job_orders, overhead_pools, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();

            $table->enum('entry_type', ['labor', 'overhead']);
            $table->foreignId('overhead_pool_id')->nullable()->constrained('overhead_pools')->restrictOnDelete();

            $table->decimal('base_quantity', 15, 4);   // labor hours, or machine hours / production units for overhead
            $table->decimal('rate_applied', 15, 4);    // resolved from standard_rates at posting time — same mechanism Mass Production uses
            $table->decimal('actual_amount', 15, 2);   // base_quantity × rate_applied

            $table->date('entry_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'job_order_id'], 'joce_company_job_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_cost_entries');
    }
};
