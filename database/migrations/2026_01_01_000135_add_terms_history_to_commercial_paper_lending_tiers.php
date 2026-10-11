<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000135_add_terms_history_to_commercial_paper_lending_tiers
//
//  Facility Renewal — Phase 2 (OD Against Commercial Paper).
//
//  A cheque's lending rate is priced off whichever tier row matches
//  its due-within-days bracket. Because a renewal can change these
//  rates, an old tier row must never silently start applying to a
//  cheque deposited after a renewal — so, ported from CashVero's
//  proven approach, every renewal creates a WHOLE NEW set of tier
//  rows tagged to the new chapter, and the OLD chapter's tier rows
//  are never touched again.
//
//  This adds that tag (credit_facility_terms_history_id) and widens
//  the uniqueness rule: a day-bracket can only have one rate PER
//  CHAPTER now, not per facility for all time — otherwise re-entering
//  the same 30/60/90-day brackets at renewal would collide with the
//  Original chapter's own rows.
//
//  FIX (first run failed — MySQL error 1553): the original version of
//  this migration dropped 'cp_lending_tiers_facility_days_unique'
//  BEFORE adding the new composite unique index. MySQL refused,
//  because that old index was silently the only thing backing the
//  table's ORIGINAL foreign key (credit_facility_id → credit_facilities),
//  created back in migration 000056 — InnoDB requires a leftmost-
//  matching index to always exist for a live foreign key. The fix is
//  simply to ADD the new composite index FIRST (it also starts with
//  credit_facility_id, so MySQL can hand that FK's backing over to
//  it), and only THEN drop the old one.
//
//  Every step below is guarded (checks what already exists first),
//  so this file is safe to run again even after a partial failure —
//  no manual database cleanup needed.
//
//  @layer    Migration
//  @depends  commercial_paper_lending_tiers, credit_facility_terms_histories
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commercial_paper_lending_tiers', 'credit_facility_terms_history_id')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->unsignedBigInteger('credit_facility_terms_history_id')->nullable()->after('credit_facility_id');
            });
        }

        // Backfill: every existing tier row belongs to its facility's
        // Original chapter. Safe to re-run — whereNull() means an
        // already-tagged row is never touched twice.
        $originalChapters = DB::table('credit_facility_terms_histories')
            ->where('is_original', true)
            ->pluck('id', 'credit_facility_id');

        foreach ($originalChapters as $facilityId => $termsHistoryId) {
            DB::table('commercial_paper_lending_tiers')
                ->where('credit_facility_id', $facilityId)
                ->whereNull('credit_facility_terms_history_id')
                ->update(['credit_facility_terms_history_id' => $termsHistoryId]);
        }

        // Add the NEW composite unique index FIRST. It also starts
        // with credit_facility_id, so MySQL can transfer the original
        // credit_facility_id → credit_facilities FK's backing onto it
        // once the old index is dropped below. Doing this the other
        // way around is exactly what caused error 1553 on the first run.
        if (! $this->indexExists('commercial_paper_lending_tiers', 'cplt_facility_chapter_days_unique')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->unique(
                    ['credit_facility_id', 'credit_facility_terms_history_id', 'due_within_days'],
                    'cplt_facility_chapter_days_unique'
                );
            });
        }

        if ($this->indexExists('commercial_paper_lending_tiers', 'cp_lending_tiers_facility_days_unique')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->dropUnique('cp_lending_tiers_facility_days_unique');
            });
        }

        if (! $this->foreignKeyExists('commercial_paper_lending_tiers', 'cplt_terms_history_foreign')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->foreign('credit_facility_terms_history_id', 'cplt_terms_history_foreign')
                    ->references('id')->on('credit_facility_terms_histories')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('commercial_paper_lending_tiers', 'cplt_terms_history_foreign')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->dropForeign('cplt_terms_history_foreign');
            });
        }

        if (! $this->indexExists('commercial_paper_lending_tiers', 'cp_lending_tiers_facility_days_unique')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->unique(['credit_facility_id', 'due_within_days'], 'cp_lending_tiers_facility_days_unique');
            });
        }

        if ($this->indexExists('commercial_paper_lending_tiers', 'cplt_facility_chapter_days_unique')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->dropUnique('cplt_facility_chapter_days_unique');
            });
        }

        if (Schema::hasColumn('commercial_paper_lending_tiers', 'credit_facility_terms_history_id')) {
            Schema::table('commercial_paper_lending_tiers', function (Blueprint $table) {
                $table->dropColumn('credit_facility_terms_history_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($result) > 0;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $result = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $constraintName]
        );
        return count($result) > 0;
    }
};