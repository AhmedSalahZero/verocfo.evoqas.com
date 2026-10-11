<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000324 — retry the foreign keys 000319 had to skip
//
//  AUDIT M-37. A "set null" column loses its orphan ids, then gets
//  the key. A required column that still has orphans stops here.
//  Keys that are already in place are left alone.
//
//  @layer    Migration
//  @depends  App\Support\ForeignKeyRepair
// ══════════════════════════════════════════════════════════════════

use App\Support\ForeignKeyRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ForeignKeyRepair::class)->apply();
    }

    public function down(): void
    {
        app(ForeignKeyRepair::class)->dropAll();
    }
};
