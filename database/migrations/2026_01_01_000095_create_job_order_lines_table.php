<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000095_create_job_order_lines_table
//
//  Job Order Production — piece 4 of 5. The job's OWN material list
//  — either copied from a job_order_template's lines at creation
//  (then fully independent — editing the template afterward never
//  touches an already-created job's lines) or entered entirely
//  ad-hoc when no template is used. Confirmed: support both.
//
//  quantity here is the TOTAL for this job (already scaled by
//  quantity_to_produce if it came from a template's per-unit
//  figure) — unlike bom_lines/job_order_template_lines, there's no
//  further scaling factor needed once a line exists on an actual
//  job.
//
//  No cost column — material cost is resolved at consumption time
//  from current WAC (stock_movements), same principle as every
//  other lines table in this app.
//
//  @layer    Migration
//  @depends  job_orders, products, units_of_measure
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();

            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 15, 4); // total for this job, not per-unit
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'job_order_id'], 'jol_company_job_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_lines');
    }
};
