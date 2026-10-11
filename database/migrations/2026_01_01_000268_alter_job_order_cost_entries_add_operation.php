<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000268_alter_job_order_cost_entries_add_operation
//
//  Extends JobOrderCostEntry (never replaces it — Section 9 of
//  VeroCFO_Manufacturing_Costing_Redesign_v1_0.md is explicit that
//  Job Order's existing cost-entry mechanism is "extended, not
//  replaced") with an OPTIONAL link to the Piece Operation a logged
//  cost belongs to:
//
//    - job_order_operation_id NULL  → the original flat path,
//      completely unchanged: a manually-typed labor/overhead entry
//      against the whole job (JobOrderService::recordLaborCost()/
//      recordOverheadCost()), for a simple Job Order that never uses
//      Pieces at all.
//    - job_order_operation_id SET   → the new path: logging hours
//      against a specific Piece's Operation
//      (JobOrderService::logPieceOperationCost()) computes
//      actual_amount automatically from the Operation's Labor
//      Category / Machine's Overhead Pool rate(s) — confirmed with
//      Mahmoud: no manual cost entry at all once a job uses Pieces.
//
//  restrictOnDelete, same protective posture as bom_operations'
//  FKs — a cost entry that already posted a real journal entry must
//  never be silently orphaned by a parent Operation disappearing (in
//  practice there is no delete path for an Operation once logged
//  against, so this is defensive, not expected to fire).
//
//  @layer    Migration
//  @depends  job_order_cost_entries, job_order_operations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_cost_entries', function (Blueprint $table) {
            $table->foreignId('job_order_operation_id')->nullable()
                  ->after('job_order_id')
                  ->constrained('job_order_operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_order_cost_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_order_operation_id');
        });
    }
};
