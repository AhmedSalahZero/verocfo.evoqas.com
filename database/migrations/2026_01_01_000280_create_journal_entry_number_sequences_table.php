<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000280_create_journal_entry_number_sequences_table
//
//  ACC-004 fix (confirmed with Mahmoud, 2026-09) — one row per
//  company per calendar year/month, holding the last entry number
//  issued for that period. JournalEntryService::generateEntryNumber()
//  locks this exact row (lockForUpdate) before reading and
//  incrementing it, so two people posting for the same company in
//  the same month at the same instant are serialized — the second
//  simply waits its turn and gets the correct next number — rather
//  than both racing to read the same "next number" from a plain
//  COUNT(*), which could let them collide.
//
//  Deliberately its own small table rather than deriving the number
//  from journal_entries itself: a dedicated row is what makes
//  row-locking possible in the first place — you cannot lock a number
//  that doesn't have its own row to lock.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_number_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            // One sequence row per company per calendar month — this
            // is the row generateEntryNumber() locks.
            $table->unique(['company_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_number_sequences');
    }
};
