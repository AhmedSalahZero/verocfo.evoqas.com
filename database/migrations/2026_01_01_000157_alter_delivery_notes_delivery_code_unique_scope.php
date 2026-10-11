<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000157_alter_delivery_notes_delivery_code_unique_scope
//
//  Same bug, same fix as 2026_01_01_000156 (Goods Receipt Notes),
//  found by audit rather than by the person hitting it yet:
//  delivery_code was unique across the ENTIRE system instead of
//  unique per company.
//
//  Paired with DeliveryNoteService now generating delivery_code's
//  YYYY/MM from delivery_date (the delivery's real business date,
//  already the field that drives the journal entry date) instead of
//  the row's created_at.
//
//  @layer    Migration
//  @depends  delivery_notes
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropUnique('delivery_notes_delivery_code_unique');
            $table->unique(['company_id', 'delivery_code']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'delivery_code']);
            $table->unique('delivery_code');
        });
    }
};
