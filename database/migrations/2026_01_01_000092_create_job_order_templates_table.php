<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000092_create_job_order_templates_table
//
//  Job Order Production — piece 1 of 5. Confirmed with Mahmoud: Job
//  Order is a SEPARATE structure from both Project and Mass
//  Production — not a reuse of either, kept apart for clarity in
//  fixing and debugging.
//
//  A template is an OPTIONAL, reusable starting point for a job's
//  material list — e.g. a racking-system company's recurring "Model
//  X Rack" job type. Once a Job Order is created from a template,
//  the job gets its own independent copy of the lines
//  (job_order_lines) — editing a template afterward never
//  retroactively changes a job already opened from it, same
//  "no silent retroactive change" principle as BOM versioning.
//
//  Deliberately NOT versioned like bill_of_materials — a template
//  here is a convenience starting point, not a governed document.
//  Keeping it structurally lighter reinforces that Job Order is a
//  genuinely different, simpler mechanism than Mass Production, not
//  a reskin of it.
//
//  A Job Order can also be created with no template at all — an
//  entirely ad-hoc material list — see migration 000094/000095.
//
//  @layer    Migration
//  @depends  companies, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete(); // the type of item this template usually produces, optional
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_templates');
    }
};
