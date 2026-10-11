<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000030_create_inventory_locations_table
//
//  Inventory locations support two-level hierarchy and three types:
//
//  Types:
//    master       → Auto-created at company setup. One per company.
//                   Cannot be deleted or deactivated.
//    warehouse    → User-defined physical locations (branches, etc.)
//    project_site → Auto-created when a Project is created.
//                   Linked to project_id. Deactivated when project
//                   is closed.
//
//  Hierarchy:
//    Level 1 — Main Location (postable if no children)
//    Level 2 — Sub-Location  (always postable)
//    Max 2 levels — enforced in service layer.
//
//  Auto-code:
//    Main: LOC-001, LOC-002 …
//    Sub:  LOC-001-01, LOC-001-02 …
//
//  project_id is nullable — only set for project_site type.
//  Linked to projects table (built in Projects stream).
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Self-referencing for two-level hierarchy
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('inventory_locations')
                  ->nullOnDelete();

            $table->string('code', 30);
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();

            // master | warehouse | project_site
            $table->string('type', 20)->default('warehouse');

            // 1 = Main Location, 2 = Sub-Location
            $table->unsignedTinyInteger('level')->default(1);

            // Linked project — only for project_site type
            // References projects table (built in Projects stream)
            $table->unsignedBigInteger('project_id')->nullable();

            // master locations cannot be deactivated
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'level']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};
