<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000262_create_machines_table
//
//  Machine — the real "Work Center" (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 4.1/4.2) — a specific, individually
//  identified physical machine the company owns. Auto-coded on
//  registration (same pattern as Product's P-00001 — see
//  Machine::nextCode()), tagged to exactly one Process.
//
//  status (Section 4.3): Active / Under Maintenance / Retired. A
//  Machine that is Under Maintenance or Retired should not be offered
//  as the DEFAULT for a new Routing (Section 5, not yet built) — but a
//  Retired machine's past production history is never hidden, same
//  "never delete history" principle as everywhere else in this app.
//  Enforced in MachineService/the Vue picker once Routing exists —
//  nothing here blocks a status change today.
//
//  fixed_asset_id (confirmed with Mahmoud, Sept 2026 — after
//  discussion of where a Machine "lives" relative to the existing
//  Fixed Assets module, Phase 1, migration 000168): OPTIONAL link to
//  an individual Fixed Asset record. Deliberately nullable and NOT
//  required — a Machine is an operational/costing identity (what
//  Process it does, which Overhead Pool(s) it feeds, whether it's
//  available to route work to right now); a Fixed Asset is an
//  accounting book record (cost basis, depreciation). The two often
//  refer to the same physical thing but not always:
//    - Old equipment bought before Vero CFO existed, or already fully
//      depreciated, may have no Fixed Asset row at all.
//    - A leased machine (Building Bible Section 29, Operational
//      Treatment B / Capital Lease) becomes a Right-of-Use Asset
//      while the lease runs, never a Fixed Asset — but it is still a
//      real physical machine that needs a Process tag and Overhead
//      Pool link.
//  Nullable unique — at most one Machine per Fixed Asset, so the same
//  accounting record can't be silently double-linked; enforced again
//  in MachineService as a friendly DomainException, same posture as
//  every other "this would collide" check in this app.
//
//  Overhead Pool linkage (Section 4.4, Option A confirmed with
//  Mahmoud): one Machine can feed several Overhead Pools, and one
//  Overhead Pool can be fed by several Machines — a genuine many-to-
//  many, not a single overhead_pool_id column here. See migration
//  000263 (machine_overhead_pools).
//
//  @layer    Migration
//  @depends  companies, processes, fixed_assets
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 200);

            $table->foreignId('process_id')->constrained('processes')->cascadeOnDelete();

            // Optional link to the accounting Fixed Asset register —
            // see class header. nullOnDelete (not cascade): if the
            // Fixed Asset record is ever removed, the Machine itself
            // — a real, still-usable piece of equipment for costing
            // purposes — must not disappear with it.
            $table->foreignId('fixed_asset_id')->nullable()
                  ->constrained('fixed_assets')->nullOnDelete();

            $table->enum('status', ['active', 'under_maintenance', 'retired'])->default('active');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique('fixed_asset_id');
            $table->index(['company_id', 'process_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
