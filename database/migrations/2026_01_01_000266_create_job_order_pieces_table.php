<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000266_create_job_order_pieces_table
//
//  Job Order Routing (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
//  Section 6). A Job Order Piece is a named component/sub-assembly
//  within one Job Order (e.g. "Uprights," "Base Plates," "Cross
//  Braces" for a racking system) — confirmed with Mahmoud (Sept
//  2026): entirely OPTIONAL. A simple Job Order stays exactly as it
//  is today (one flat labor/overhead entry via JobOrderCostEntry with
//  no piece link) — Pieces only get created for jobs complex enough
//  to need per-part cost visibility.
//
//  Pieces are NOT sequential — confirmed with Mahmoud: two pieces can
//  be genuinely in progress at the same time (no dependency between
//  them). `status` is what lets the Job Order screen show, at a
//  glance, which pieces are done, which are running, and which
//  haven't started — it transitions not_started → in_progress
//  automatically the first time cost is logged against one of the
//  piece's operations (see JobOrderService::logPieceOperationCost()),
//  and → completed only via an explicit user action
//  (JobOrderService::completePiece()), mirroring the Job Order's own
//  explicit complete() action rather than any automatic inference.
//
//  Confirmed with Mahmoud: once every Piece is Completed, the WHOLE
//  Job Order completes into Finished Goods as ONE product (the job's
//  own product_id) — never as separate piece-products, and the
//  customer invoice only ever shows that one product. This needs NO
//  change to JobOrderService::complete()'s existing FG-receipt logic
//  (it already only knows about job.product_id / quantity_to_produce)
//  — only a new guard added there requiring every piece be Completed
//  first when the job has any.
//
//  @layer    Migration
//  @depends  job_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('quantity', 15, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');

            $table->timestamps();

            $table->index(['company_id', 'job_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_pieces');
    }
};
