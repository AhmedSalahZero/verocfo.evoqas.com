<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000254_alter_bill_of_materials_add_status_lifecycle
//
//  BOM Draft/Active/Superseded fix — confirmed with Mahmoud
//  (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md, Section 1).
//
//  PROBLEM BEING FIXED: is_active was a plain boolean with no Draft
//  state — a user could edit a BOM's materials at any time, even
//  after Production Orders had already used it, with no way to fix
//  a mistake before it went live. effective_date already existed on
//  this table (migration 000085) but was NEVER actually used by
//  ProductionOrderService to decide which BOM applies — it only
//  drove which BOM a user manually picked from a dropdown of
//  "currently active" versions. This migration adds the status
//  column that makes both problems fixable; BillOfMaterialService
//  and ProductionOrderService are updated in the same delivery to
//  actually use it.
//
//  status: 'draft' | 'active' | 'superseded'.
//    draft      — being built/corrected, fully editable, cannot be
//                 used by a Production Order yet.
//    active     — locked, in effect from effective_date. Only one
//                 per product at a time (enforced in
//                 BillOfMaterialService, not at the DB level, same
//                 pattern as every other "one active X" rule here).
//    superseded — a former Active version, kept forever, read-only,
//                 and STILL queried for historical resolution —
//                 BillOfMaterialService::resolveEffective() picks
//                 whichever active-or-superseded version's
//                 effective_date is the latest one on or before the
//                 date being calculated, exactly so a Production
//                 Order dated before a later switchover keeps
//                 resolving to the version that was actually in
//                 effect on that date.
//
//  Existing rows: every is_active = true row becomes 'active' (there
//  is at most one per product, by the existing application-level
//  rule); every is_active = false row becomes 'superseded' (nothing
//  in this fresh build has ever been left in an ambiguous state —
//  a version is only ever deactivated by a newer one taking over).
//  is_active itself is then dropped — status is the single source
//  of truth going forward, avoiding two columns that could drift
//  out of sync with each other.
//
//  @layer    Migration
//  @depends  bill_of_materials
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft')->after('is_active');
        });

        DB::table('bill_of_materials')->where('is_active', true)->update(['status' => 'active']);
        DB::table('bill_of_materials')->where('is_active', false)->update(['status' => 'superseded']);

        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->dropColumn('is_active');
            $table->index(['company_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('version');
        });

        DB::table('bill_of_materials')->where('status', 'active')->update(['is_active' => true]);
        DB::table('bill_of_materials')->whereIn('status', ['draft', 'superseded'])->update(['is_active' => false]);

        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'product_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
