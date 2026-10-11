<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000267_create_job_order_operations_table
//
//  One step in a Job Order Piece's own routing (Section 6.2) — same
//  building blocks as BOM Operation (Process, Machine, Labor
//  Category), just assembled fresh per job/piece instead of reused
//  per product, and describing the machine ACTUALLY used rather than
//  a "default" for standard costing. No overhead_pool_id here, same
//  reasoning as BomOperation — overhead is derived at logging time
//  from machine_id's own linked pools (Machine::machineOverheadPools),
//  never duplicated onto the operation itself.
//
//  No status column here — unlike the Piece, an individual Operation
//  doesn't need its own not_started/in_progress/completed state for
//  this first cut; the Piece's status is what the Job Order screen
//  shows. (Section 8's "Production Order / Job Order actual-machine
//  override" — a further refinement letting a single logged entry
//  name a different machine than the operation's own — is explicitly
//  OUT of scope for this build; flagged here rather than silently
//  assumed included.)
//
//  @layer    Migration
//  @depends  job_order_pieces, processes, machines, labor_categories
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('job_order_piece_id')->constrained('job_order_pieces')->cascadeOnDelete();
            $table->unsignedInteger('sequence');

            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('labor_category_id')->constrained('labor_categories')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['job_order_piece_id', 'sequence'], 'joo_piece_sequence_unique');
            $table->index(['company_id', 'job_order_piece_id']);
            $table->index(['company_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_operations');
    }
};
