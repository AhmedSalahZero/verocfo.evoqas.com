<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000154_alter_sales_orders_add_issuance_date
//
//  Same fix as 2026_01_01_000153 for Purchase Orders, applied to
//  Sales Orders. See that migration's header for the full rationale.
//
//  @layer    Migration
//  @depends  sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('issuance_date')->nullable()->after('so_code');
        });

        DB::statement('UPDATE sales_orders SET issuance_date = DATE(created_at) WHERE issuance_date IS NULL');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('issuance_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('issuance_date');
        });
    }
};
