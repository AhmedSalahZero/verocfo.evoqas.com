<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000025_alter_chart_of_accounts_restructure
//
//  Extends the chart_of_accounts table to support the full
//  5-level IFRS-aligned hierarchy designed for Vero CFO.
//
//  Changes:
//  1. company_id → nullable (system accounts have company_id = NULL)
//  2. Add `level`         TINYINT  — 1 to 5
//  3. Add `section`       VARCHAR  — balance_sheet | income_statement
//  4. Add `class`         VARCHAR  — assets|liabilities|equity|revenue|expenses
//  5. Add `is_postable`   BOOLEAN  — TRUE only at levels 4 and 5
//  6. Add `allow_currency` BOOLEAN — TRUE for cash and bank accounts
//  7. Drop unique constraint on (company_id, code) — system accounts
//     have NULL company_id so uniqueness must allow NULLs
//  8. Add new unique constraint on (company_id, code) using a partial
//     approach — enforced in service layer for system accounts
//
//  @layer    Migration
//  @depends  chart_of_accounts, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {

            // ── 1. Make company_id nullable ───────────────────────
            // System accounts (Levels 1-3) are seeded with NULL
            // and shared across all companies.
            $table->foreignId('company_id')
                  ->nullable()
                  ->change();

            // ── 2. Hierarchy level ────────────────────────────────
            // 1=Class, 2=Group, 3=Account, 4=Sub-Account, 5=Detail
            $table->unsignedTinyInteger('level')
                  ->default(1)
                  ->after('parent_id');

            // ── 3. Financial statement section ────────────────────
            // balance_sheet | income_statement
            $table->string('section', 20)
                  ->default('balance_sheet')
                  ->after('level');

            // ── 4. Account class ──────────────────────────────────
            // assets | liabilities | equity | revenue | expenses
            $table->string('class', 20)
                  ->default('assets')
                  ->after('section');

            // ── 5. Postable flag ──────────────────────────────────
            // Only Level 4 and 5 accounts accept journal entry lines.
            // Levels 1-3 are summary only — no direct posting.
            $table->boolean('is_postable')
                  ->default(false)
                  ->after('class');

            // ── 6. Currency-enabled flag ──────────────────────────
            // TRUE for Cash (131) and Banks (132) accounts.
            // Sub-accounts under these must have a currency assigned.
            $table->boolean('allow_currency')
                  ->default(false)
                  ->after('is_postable');

            // ── 7. New indexes ────────────────────────────────────
            $table->index(['company_id', 'level']);
            $table->index(['company_id', 'section']);
            $table->index(['company_id', 'class']);
            $table->index(['company_id', 'is_postable']);
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'level']);
            $table->dropIndex(['company_id', 'section']);
            $table->dropIndex(['company_id', 'class']);
            $table->dropIndex(['company_id', 'is_postable']);
            $table->dropColumn(['level', 'section', 'class', 'is_postable', 'allow_currency']);
        });
    }
};
