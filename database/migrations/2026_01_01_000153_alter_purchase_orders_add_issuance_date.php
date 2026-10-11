<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000153_alter_purchase_orders_add_issuance_date
//
//  Adds issuance_date — the real business date a Purchase Order was
//  issued, set by the user, separate from start_date/end_date (the
//  contract/delivery period). Until now, po_code's year/month came
//  from the server's clock at save time, which is wrong whenever a
//  PO is entered after the fact for an earlier date (confirmed gap,
//  raised by Mahmoud).
//
//  From this migration on, po_code's year/month is generated from
//  issuance_date instead of "now" — see PurchaseOrderService.
//
//  Existing rows: backfilled with created_at's date, since that is
//  exactly what their existing po_code was already generated from —
//  this keeps every already-issued number consistent with its own
//  issuance_date, causing zero renumbering on old data.
//
//  @layer    Migration
//  @depends  purchase_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('issuance_date')->nullable()->after('po_code');
        });

        DB::statement('UPDATE purchase_orders SET issuance_date = DATE(created_at) WHERE issuance_date IS NULL');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('issuance_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('issuance_date');
        });
    }
};
