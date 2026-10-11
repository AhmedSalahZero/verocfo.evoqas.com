<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000295 — cbe_corridor_rates.company_id becomes NOT NULL
//
//  AUDIT H9 (Sept 2026) — "add NOT NULL on tenant tables". Every
//  tenant table in the schema already has a NOT NULL company_id
//  EXCEPT the few that hold shared system rows on purpose (currencies,
//  chart_of_accounts, payment_terms, sales_channels, units_of_measure)
//  or platform-level records (users, audit_log, notifications,
//  conversations) — and cbe_corridor_rates. Migration 000292 (Audit
//  H6) gave every corridor rate a company and removed the company-less
//  ones, but left the column nullable only so that backfill could run.
//  This closes it: a corridor rate without a company can no longer be
//  saved, so it can never leak to every client again.
//
//  Safety: any stray NULL row left behind is deleted first (000292
//  already copied every such row to each company before removing it).
//
//  @layer    Migration
//  @depends  cbe_corridor_rates
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cbe_corridor_rates')->whereNull('company_id')->delete();

        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }
};
