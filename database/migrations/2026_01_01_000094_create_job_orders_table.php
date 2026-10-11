<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000094_create_job_orders_table
//
//  Job Order Production — piece 3 of 5. The job itself — same
//  physical shape as production_orders (product, quantity, raw
//  material + output locations, status lifecycle) but a genuinely
//  separate table, per Mahmoud's explicit "keep it separate for
//  clarity" instruction. Material movement lives in stock_movements
//  (job_consumption/job_receipt — reserved for exactly this in
//  migration 000082) via reference_type/reference_id. Labor/overhead
//  cost lives in job_order_cost_entries (migration 000096).
//
//  job_order_template_id is nullable — a job can be opened from a
//  saved template OR entirely ad-hoc (confirmed: support both).
//  Recorded here purely for traceability ("which template, if any,
//  this job started from") — the job's actual lines live
//  independently in job_order_lines regardless.
//
//  sales_order_id is nullable — the customer order driving this
//  job, when there is one. Not required: a job can exist without a
//  linked Sales Order (e.g. a speculative/internal batch).
//
//  ESTIMATED COST (confirmed): captured at job opening, per unit —
//  same per-unit shape as production_orders' standard cost columns,
//  but philosophically different: these are NOT system-derived from
//  a rate table the way Mass Production's are.
//    - estimated_material_cost_per_unit: auto-calculated from this
//      job's own lines × current WAC at creation (same mechanism as
//      Mass Production's material calc, just not locked to a
//      governed BOM version)
//    - estimated_labor_cost_per_unit / estimated_overhead_cost_per_unit:
//      entered directly by whoever quoted the job — there is no
//      repeatable routing figure to calculate them from, since each
//      job is a one-off
//  Compared against ACTUAL cost (from stock_movements + cost
//  entries) at completion — the Job Order equivalent of Mass
//  Production's standard-vs-actual variance.
//
//  status lifecycle identical to production_orders: draft →
//  in_progress → completed → closed (+ cancelled). completed =
//  job_receipt posted, Finished Goods updated, at ACTUAL cost —
//  never the estimate.
//
//  @layer    Migration
//  @depends  companies, products, job_order_templates, sales_orders,
//            inventory_locations, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('job_code', 30); // JOB/YYYY/MM/00001, immutable after creation
            $table->foreignId('job_order_template_id')->nullable()->constrained('job_order_templates')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_to_produce', 15, 4);

            $table->foreignId('raw_material_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('output_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->enum('status', ['draft', 'in_progress', 'completed', 'closed', 'cancelled'])->default('draft');

            $table->date('planned_start_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_completion_date')->nullable();

            // ── Estimated cost snapshot — locked at creation, see header ──
            $table->decimal('estimated_material_cost_per_unit', 15, 4)->nullable();
            $table->decimal('estimated_labor_cost_per_unit', 15, 4)->nullable();
            $table->decimal('estimated_overhead_cost_per_unit', 15, 4)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'job_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
