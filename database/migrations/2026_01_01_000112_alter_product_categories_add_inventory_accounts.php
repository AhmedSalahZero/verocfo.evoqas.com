<?php

// ══════════════════════════════════════════════════════════════════
//  alter_product_categories_add_inventory_accounts
//
//  Adds two nullable, auto-created sub-account links to
//  product_categories: one for Raw Material / Consumable stock
//  (under the shared 135001 parent) and one for Finished Goods /
//  Trading stock (under the shared 135003 parent).
//
//  Confirmed with Mahmoud (July 2026): with companies carrying up to
//  10,000+ SKUs, a sub-account per PRODUCT is unworkable — the Chart
//  of Accounts must stay at category granularity. Each column starts
//  NULL and is populated automatically the first time a physical
//  product needing that bucket is assigned to the category — never
//  by the user, never in advance. See ProductService::
//  resolveCategoryInventoryAccount().
//
//  @layer    Migration
//  @depends  product_categories, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('raw_material_account_id')->nullable()->after('expense_account_id');
            $table->unsignedBigInteger('finished_goods_account_id')->nullable()->after('raw_material_account_id');

            $table->foreign('raw_material_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
            $table->foreign('finished_goods_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropForeign(['raw_material_account_id']);
            $table->dropForeign(['finished_goods_account_id']);
            $table->dropColumn(['raw_material_account_id', 'finished_goods_account_id']);
        });
    }
};
