<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000259_create_labor_categories_table
//
//  Labor Categories (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
//  Section 3) — replaces the single company-wide blended labor rate.
//  Confirmed with Mahmoud: a company defines as many labor categories
//  as it wants (e.g. "Skilled Operator," "Unskilled Packer," "Machine
//  Technician"), each with its own Standard Rate — same shape as
//  Overhead Pool (migration 000087): this table is just identity, the
//  rate itself lives in standard_rates (migration 000260 adds the
//  labor_category_id column there).
//
//  Deliberately no allocation_base column here, unlike OverheadPool —
//  a labor category's "base" is always hours, always paired with
//  whichever Routing operation specifies it (Section 5); there's no
//  equivalent choice to make at the category level the way an
//  overhead pool must pick labor_hours vs. machine_hours vs.
//  production_units.
//
//  The existing 'labor' StandardRate rate_type (a single blanket
//  company-wide rate) is left untouched by this migration — it keeps
//  working exactly as before for BOM's current single "standard
//  labor hours per batch" field, until Section 5 (Routing) replaces
//  that field with per-category hours and retires the blanket rate.
//  Labor Categories and their own rates are additive, ready for
//  Section 5 to consume.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_categories');
    }
};
