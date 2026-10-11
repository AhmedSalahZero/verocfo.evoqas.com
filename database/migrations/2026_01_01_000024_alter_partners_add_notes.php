<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000024_alter_partners_add_notes
//
//  Adds the notes column to the partners table.
//  Free-text internal notes about a partner — optional.
//
//  @layer    Migration
//  @depends  partners
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
