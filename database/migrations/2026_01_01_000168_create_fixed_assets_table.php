<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000168_create_fixed_assets_table
//
//  Fixed Assets module — Phase 1. The Asset Register — one row per
//  INDIVIDUAL physical asset (confirmed with Mahmoud: a PO line for
//  "5 laptops" creates 5 separate rows here, each depreciated on its
//  own — never one combined row per line).
//
//  Lifecycle, confirmed with Mahmoud:
//    1. Created the moment the asset is physically received (via
//       Goods Receipt Note posting) — cost_basis starts at the
//       receipt/PO's provisional cost.
//    2. Stays 'capitalizing' while more cost can still be added to it
//       (freight, installation — see fixed_asset_cost_lines) from
//       ANY supplier/PO/Bill, not just the one that created it.
//    3. Becomes 'in_service' once depreciation_start_date passes, or
//       the user manually marks it in service — after that, further
//       costs are EXPENSED instead of capitalized (enforced in
//       FixedAssetService, not here).
//    4. 'disposed' is reserved for a later phase (sale/scrap/write-
//       off) — not built yet, but the status column is ready for it.
//
//  fixed_asset_account_id / accumulated_depreciation_account_id are
//  SNAPSHOTS copied from the originating product at creation time —
//  never re-derived — so a later change to the product's own account
//  mapping never silently reclassifies an asset that's already been
//  posting to a specific pair for months.
//
//  depreciation_start_date defaults to (received_date + 1 month) —
//  confirmed with Mahmoud — but is a real, editable column, not a
//  computed value, since the user may need to override it per asset.
//
//  accumulated_depreciation is a running convenience total (source of
//  truth is SUM(fixed_asset_depreciation_entries.amount) where
//  status = 'posted' — this column just avoids re-summing on every
//  read; FixedAssetService keeps the two in sync on every post/reverse.
//
//  source_type/source_id trace back to whichever line first created
//  this asset (always a goods_receipt_note_line today — Phase 1 has
//  no other creation path) — kept as loose, nullable references
//  (not a real FK) since the line itself may later be voided/reopened
//  independently of the asset it already created.
//
//  @layer    Migration
//  @depends  companies, products, chart_of_accounts, currencies,
//            inventory_locations, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('asset_code', 30);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name', 200);
            $table->string('serial_number', 150)->nullable();

            $table->foreignId('fixed_asset_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();

            $table->decimal('cost_basis', 15, 2)->default(0);
            $table->decimal('residual_value', 15, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('depreciation_method', 20)->default('straight_line');

            $table->date('received_date');
            $table->date('depreciation_start_date');
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);

            $table->enum('status', ['capitalizing', 'in_service', 'disposed'])->default('capitalizing');
            $table->date('in_service_at')->nullable();

            // Traceability to the line that first created this asset —
            // loose reference, not a real FK (see class header).
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->unique(['company_id', 'asset_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
