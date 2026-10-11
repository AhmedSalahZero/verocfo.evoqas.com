<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_sales_dashboard_notes_table
//
//  Analyst Notes on the Analytics > Sales > Dashboard page — free-text
//  commentary an analyst writes about a specific date range (e.g.
//  "Q3 dip was the Cairo warehouse move, not a real slowdown"), saved
//  so the next person looking at the same range sees the context.
//
//  Scoped to (company_id, date_from, date_to) — the dashboard only
//  loads notes for the date range currently selected, same as CFO
//  Tools' sales_dashboard_notes table. `note` is stored as HTML from
//  the dashboard's lightweight rich-text box (bold/italic/lists).
//
//  Ownership: only the author can edit/delete their own note. Not a
//  financial document, so no audit_log entry and no "reverse" pattern
//  — a plain hard delete is fine here. user_id is nullable + set null
//  on user deletion (not cascaded) so a note survives if its author's
//  account is later removed — same pattern as audit_log.user_id.
//
//  @layer    Migration
//  @depends  companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_dashboard_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->longText('note');
            $table->timestamps();

            $table->index(['company_id', 'date_from', 'date_to'], 'sdn_company_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_dashboard_notes');
    }
};
