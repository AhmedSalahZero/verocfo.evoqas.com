<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000132_alter_delivery_notes_add_from_location
//
//  Found while building DeliveryNoteService: migration 000129 never
//  captured WHICH physical Inventory Location the goods ship from —
//  StockLedgerService::issue() requires one (same as any outbound
//  stock movement, e.g. Internal Transfer's own from_location_id).
//  Added as a new migration rather than editing 000129 directly, per
//  Building Bible Rule 5 (never modify an existing migration).
//
//  Header-level, not per-line — one Delivery Note ships from one
//  physical location, same assumption GRN makes for its own single
//  receiving location.
//
//  @layer    Migration
//  @depends  delivery_notes, inventory_locations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->foreignId('from_location_id')->nullable()->after('sales_order_id')
                ->constrained('inventory_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropForeign(['from_location_id']);
            $table->dropColumn('from_location_id');
        });
    }
};
