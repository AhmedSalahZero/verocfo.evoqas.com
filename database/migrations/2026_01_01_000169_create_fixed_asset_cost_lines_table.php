<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000169_create_fixed_asset_cost_lines_table
//
//  Fixed Assets module — Phase 1. Append-only ledger of every line
//  that has ever contributed to an asset's cost basis — confirmed
//  with Mahmoud: a machine (100,000) and its transportation (5,000,
//  possibly from a DIFFERENT supplier/PO entirely) both roll into one
//  asset's total cost (105,000). This table is that roll-up's audit
//  trail — fixed_assets.cost_basis is always the SUM of this table's
//  rows for that asset, kept in sync by FixedAssetService on every
//  add/reverse rather than derived on read.
//
//  goods_receipt_note_line_id / supplier_bill_line_id: exactly one of
//  the two is set per row (never both, never neither) — a cost line
//  either arrived as a physical receipt (the asset itself, or an
//  ancillary cost line on the same or a different GRN) or as a
//  Bill line with no matching GRN (e.g. an installer's service bill
//  that never went through goods receipt at all). Both nullable so
//  either path works; validated at the service layer, not the DB.
//
//  cost_type is informational only (drives reporting/breakdown, e.g.
//  "show me all freight costs capitalized this quarter") — it does
//  NOT change where the amount posts; every cost_type debits the
//  same fixed_asset_account_id on the parent asset.
//
//  is_provisional: true the moment a GRN line creates/adds to a cost
//  line (before its own Bill exists), flipped to false once the
//  matching Supplier Bill confirms the amount (see Rule mirrored from
//  Building Bible §27 GRNI clearing — same provisional-then-confirmed
//  shape, just scoped to an asset's cost instead of inventory).
//
//  @layer    Migration
//  @depends  fixed_assets, goods_receipt_note_lines, supplier_bill_lines, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();

            $table->foreignId('goods_receipt_note_line_id')->nullable()
                  ->constrained('goods_receipt_note_lines')->nullOnDelete();
            $table->foreignId('supplier_bill_line_id')->nullable()
                  ->constrained('supplier_bill_lines')->nullOnDelete();

            $table->enum('cost_type', ['asset_cost', 'freight', 'installation', 'customs', 'other'])
                  ->default('asset_cost');
            $table->decimal('amount', 15, 2);
            $table->boolean('is_provisional')->default(true);
            $table->string('description', 300)->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_cost_lines');
    }
};
