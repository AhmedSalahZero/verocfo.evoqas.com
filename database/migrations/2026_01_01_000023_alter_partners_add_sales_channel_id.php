<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000023_alter_partners_add_sales_channel_id
//
//  Replaces the free-text sales_channel column on partners with
//  a proper FK to sales_channels table.
//
//  This ensures analysis integrity — sales channel is now a
//  controlled master value, not free text.
//
//  @layer    Migration
//  @depends  partners, sales_channels
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            // Drop the old free-text column
            $table->dropColumn('sales_channel');

            // Add FK to sales_channels master
            $table->foreignId('sales_channel_id')
                  ->nullable()
                  ->after('sales_zone')
                  ->constrained('sales_channels')
                  ->nullOnDelete();

            $table->index(['company_id', 'sales_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'sales_channel_id']);
            $table->dropConstrainedForeignId('sales_channel_id');
            $table->string('sales_channel', 50)->nullable()->after('sales_zone');
        });
    }
};
