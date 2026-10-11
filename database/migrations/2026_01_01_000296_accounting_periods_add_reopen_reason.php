<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000296 — accounting_periods.reopen_reason
//
//  AUDIT H4 (Sept 2026). Reopening a closed month now requires a
//  Company Admin (or the Super Admin) and a written reason. The reason
//  is stored on the period itself — shown next to its "Reopened"
//  badge — as well as in the audit log, so the question "why was March
//  reopened?" always has an answer on screen, not only in a log.
//
//  @layer    Migration
//  @depends  accounting_periods
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->text('reopen_reason')->nullable()->after('reopened_by');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropColumn('reopen_reason');
        });
    }
};
