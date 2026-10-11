<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000167_add_fixed_asset_fields_to_products
//
//  Fixed Assets module — Phase 1. Confirmed with Mahmoud: Fixed Asset
//  purchasing reuses the existing Product/Purchase Order engine —
//  'fixed_asset' becomes a new allowed `nature` value (that column is
//  a plain string(20), not a DB enum — see migration 000033 — so no
//  column-type change is needed, only the two new account columns
//  below plus application-level validation, mirroring how
//  'tradable'/'manufactured'/'service' already work).
//
//  fixed_asset_account_id / accumulated_depreciation_account_id: the
//  111xxx / 113xxx category pair this product's assets post to —
//  same "auto-assigned, system-resolved" pattern as the existing
//  revenue_account_id/cogs_account_id/inventory_account_id trio.
//  ONE shared GL account per category (confirmed — not per
//  individual asset, unlike Leasing's per-contract accounts); every
//  asset created from this product routes to the same pair.
//
//  default_useful_life_months / default_residual_value: copied onto
//  every FixedAsset row created from this product at receipt time —
//  a sensible starting point (e.g. "Computer Equipment" products
//  default to 36 months, 0 residual), editable per individual asset
//  afterward since real-world assets do vary even within one category.
//
//  @layer    Migration
//  @depends  products (000033), chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('fixed_asset_account_id')
                  ->nullable()->after('inventory_account_id')
                  ->constrained('chart_of_accounts')->nullOnDelete();

            $table->foreignId('accumulated_depreciation_account_id')
                  ->nullable()->after('fixed_asset_account_id')
                  ->constrained('chart_of_accounts')->nullOnDelete();

            $table->unsignedInteger('default_useful_life_months')->nullable()->after('accumulated_depreciation_account_id');
            $table->decimal('default_residual_value', 15, 2)->nullable()->after('default_useful_life_months');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_asset_account_id');
            $table->dropConstrainedForeignId('accumulated_depreciation_account_id');
            $table->dropColumn(['default_useful_life_months', 'default_residual_value']);
        });
    }
};
