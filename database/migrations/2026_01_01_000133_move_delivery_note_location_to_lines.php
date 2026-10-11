<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000133_move_delivery_note_location_to_lines
//
//  Found and corrected per Mahmoud's direct feedback (July 2026): a
//  single Delivery Note can ship different lines from different
//  physical locations — e.g. Product A's ordered quantity split
//  across Warehouse A and Warehouse B on the same delivery. Migration
//  000132 put `from_location_id` on the delivery_notes HEADER, which
//  only allows one location per whole delivery. This migration moves
//  it down to delivery_note_lines instead, and drops the now-wrong
//  header column — a new migration, not an edit to 000132, per the
//  house rule against ever modifying an already-shipped migration.
//
//  Nullable at the schema level (existing draft rows, if any, won't
//  have a value yet) — DeliveryNoteRequest enforces it as required
//  per line at the application layer, same "schema permissive,
//  validation strict" split used throughout this codebase.
//
//  @layer    Migration
//  @depends  delivery_notes, delivery_note_lines, inventory_locations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('sales_order_line_id')
                ->constrained('inventory_locations')->nullOnDelete();
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropForeign(['from_location_id']);
            $table->dropColumn('from_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->foreignId('from_location_id')->nullable()
                ->constrained('inventory_locations')->nullOnDelete();
        });

        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
