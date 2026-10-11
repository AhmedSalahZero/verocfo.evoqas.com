<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000098_create_journal_entries_table
//
//  Foundation table for the whole Accounting Engine GL. Every
//  auto-posted business transaction (Goods Receipt Note, and every
//  future one — Supplier Bill, Payments, Sales Invoice, Payroll...)
//  and every manual journal entry (Phase 4 exception tool) writes
//  here through JournalEntryService::post() — nothing else is
//  allowed to write to this table (same "one gatekeeper service"
//  pattern already established by StockLedgerService).
//
//  Confirmed design (Mahmoud, July 2026): build the real foundation
//  now, because GRN is the first transaction in the whole roadmap
//  that needs to touch the GL, and every transaction after it needs
//  the same auto-posting path. Feature surface stays deliberately
//  minimal — no recurring entries, no approval workflow, no draft
//  state (those are Phase 4 conveniences layered on top of this same
//  table later) — but correctness, indexing, and multi-tenancy are
//  full production-grade from this first migration, not a shortcut.
//
//  `source_type` / `source_id` is the polymorphic link back to
//  whatever business document caused this entry (e.g.
//  'goods_receipt_note' / 123). `source_type = 'manual'` is what the
//  future Manual Journal Entry tool (Phase 4) will use — it is not a
//  special case, just another caller of the same post() method.
//
//  Entries are append-only — Rule 15.2 (no soft deletes) plus the
//  platform-wide "no hard deletes on financial data" rule apply here
//  the same as everywhere else. A posted entry is never edited; it
//  is only ever reversed by a second, linked entry
//  (`reversed_by_journal_entry_id` / `reverses_journal_entry_id`).
//
//  Entry number format: JE/YYYY/MM/00001 — immutable after creation,
//  same generator pattern as SO/PO codes.
//
//  Account balances are intentionally NOT cached on this table or
//  anywhere else — Trial Balance / GL / Balance Sheet (Phase 5) will
//  derive every balance by summing journal_entry_lines directly, so
//  there is exactly one source of truth for money in the system and
//  no second value that can ever drift out of sync with it. If query
//  performance on large ledgers ever needs it, a
//  Cache::remember()-backed rollup (Rule 8.4) can be added later
//  without touching this table.
//
//  @layer    Migration
//  @depends  companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('entry_number', 30);
            $table->date('entry_date');

            // Polymorphic origin — the business document that caused this
            // posting. 'manual' is used by the future Manual JE tool.
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('narration');

            $table->enum('status', ['posted', 'reversed'])->default('posted');

            // Reversal linkage — both directions kept so either entry can
            // be navigated to from the other without a reverse lookup scan.
            $table->foreignId('reverses_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->unique(['company_id', 'entry_number']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
