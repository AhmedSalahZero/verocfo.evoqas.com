<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000093_create_job_order_template_lines_table
//
//  Job Order Production — piece 1 of 5 (continued). Material
//  components on a template, expressed PER UNIT of whatever the
//  template typically produces — scaled by JobOrderService against
//  a Job Order's own quantity_to_produce when a job is created from
//  this template.
//
//  No cost column here — same principle as bom_lines: material cost
//  is resolved at Job Order creation time from the component's
//  current company-wide WAC, never stored or frozen on the
//  template line itself.
//
//  @layer    Migration
//  @depends  job_order_templates, products, units_of_measure
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_order_template_id')->constrained('job_order_templates')->cascadeOnDelete();

            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 4);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'job_order_template_id'], 'jotl_company_template');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_template_lines');
    }
};
