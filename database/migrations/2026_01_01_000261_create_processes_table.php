<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000261_create_processes_table
//
//  Process (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md, Section
//  4.1) — a simple, company-defined named list of operation
//  categories: Welding, Cutting, Assembly, Painting, Packing.
//  Deliberately just identity, same shape as LaborCategory (migration
//  000259) and OverheadPool (migration 000087) — no rate, no
//  allocation base, nothing else to configure here.
//
//  Confirmed with Mahmoud: Process is NOT the same thing as Machine
//  (migration 000262) — Process is the category of work; Machine is
//  the specific physical piece of equipment tagged to exactly one
//  Process. A factory can own several Machines performing the same
//  Process (e.g. Machine WA and Machine WB, both "Welding") — that's
//  normal, not a duplicate.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
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
        Schema::dropIfExists('processes');
    }
};
