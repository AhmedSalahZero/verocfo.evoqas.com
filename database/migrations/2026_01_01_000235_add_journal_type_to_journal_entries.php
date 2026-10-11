<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000235_add_journal_type_to_journal_entries
//
//  Adds an explicit journal_type column ('automated' | 'manual') to
//  journal_entries, requested by Mahmoud (September 2026) as a
//  simple, directly-filterable/displayable distinction — rather than
//  every screen having to infer it by checking whether source_type
//  equals 'manual' against the other ~30 possible source_type
//  strings every auto-posting service uses.
//
//  Set automatically, once, inside JournalEntryService::post() —
//  every existing auto-posting caller needs zero changes; only the
//  new Manual Journal Entry tool ever produces 'manual' rows, by
//  calling post() with source_type = JournalEntry::SOURCE_MANUAL
//  (a constant that already existed on the model, unused, since the
//  original July 2026 migration reserved it for exactly this).
//
//  Backfilled for any pre-existing rows: every entry posted before
//  this column existed was, by definition, auto-posted — there was
//  no manual entry tool yet.
//
//  @layer    Migration
//  @depends  journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('journal_type', 20)->default('automated')->after('source_id');
            $table->index(['company_id', 'journal_type']);
        });

        // Backfill: nothing existing could have been manual (the tool
        // didn't exist yet), but be explicit rather than rely on the
        // column default alone for already-posted historical rows.
        \Illuminate\Support\Facades\DB::table('journal_entries')->update(['journal_type' => 'automated']);
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'journal_type']);
            $table->dropColumn('journal_type');
        });
    }
};
