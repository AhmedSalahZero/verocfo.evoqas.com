<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000151_alter_sales_orders_so_code_unique_scope
//
//  Same fix as 2026_01_01_000150 for Purchase Orders, applied to
//  Sales Orders: so_code was unique across the ENTIRE system
//  instead of unique per company. Replaces the old plain index on
//  (company_id, so_code) with a proper unique constraint on those
//  same two columns.
//
//  @layer    Migration
//  @depends  sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_so_code_unique');
            $table->dropIndex(['company_id', 'so_code']);
            $table->unique(['company_id', 'so_code']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'so_code']);
            $table->index(['company_id', 'so_code']);
            $table->unique('so_code');
        });
    }
};
