<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000245_create_plan_manpower_hiring_lines_table
//
//  Planned new hires — one row per plan_manpower_title per plan year
//  (plan_year is 1-based: 1 = the plan's first year). Confirmed
//  design: the user enters ONLY a headcount count per year here — no
//  per-hire salary, no month-level detail. New hires cost the
//  title's avg_current_salary (or the user's own estimate, for a
//  brand-new title), growing by the plan's one annual salary increase
//  % — see plans-level manpower assumption, applied in the
//  calculation engine, not stored per row here.
//
//  @layer    Migration
//  @depends  plan_manpower_titles
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_manpower_hiring_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_manpower_title_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('plan_year');
            $table->unsignedSmallInteger('new_hires_count')->default(0);

            $table->timestamps();

            $table->unique(['plan_manpower_title_id', 'plan_year'], 'plan_manpower_hiring_title_year_unique');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_manpower_hiring_lines');
    }
};
