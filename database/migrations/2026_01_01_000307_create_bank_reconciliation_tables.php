<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000307_create_bank_reconciliation_tables
//
//  Audit H-25 — bank reconciliation, the core month-end control.
//
//  bank_statement_lines
//    The lines of the BANK'S OWN statement, imported from Excel/CSV
//    or typed in, per bank account. Amounts are in the ACCOUNT'S OWN
//    currency (a USD account is reconciled in USD, never in EGP) and
//    signed from the company's point of view: + money in (deposit),
//    − money out (withdrawal). A line is never edited after import;
//    a wrong one is deleted (only while unmatched) and re-entered.
//    line_hash stops the same file being imported twice.
//
//  bank_reconciliations
//    One reconciliation of one bank account up to one statement date
//    and the bank's closing balance on that date. Status 'draft'
//    (being worked on) → 'completed' (difference is exactly zero).
//    The figures of the final report are snapshotted on completion so
//    a completed reconciliation never changes afterwards.
//
//  bank_reconciliation_items
//    The ledger lines ("book items") that have been cleared in a
//    reconciliation — either matched to a statement line, or simply
//    ticked as appearing on the bank's statement. A ledger line can be
//    cleared only once, ever (unique) — that is what stops it being
//    counted in two months.
//
//  Nothing here posts to the general ledger — reconciling is a
//  control, not a transaction. Items missing from the books (bank
//  charges, interest) are booked through the normal screens and then
//  matched.
//
//  @layer    Migration
//  @depends  companies, bank_accounts, currencies, journal_entry_lines, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('bank_account_id');
            $table->date('line_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);               // signed, account's own currency: + in, − out
            $table->char('line_hash', 40);
            $table->enum('source', ['import', 'manual'])->default('import');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->unique(['company_id', 'bank_account_id', 'line_hash'], 'bsl_unique_hash');
            $table->index(['company_id', 'bank_account_id', 'line_date'], 'bsl_account_date_idx');
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('currency_id');
            $table->date('statement_date');
            $table->decimal('statement_ending_balance', 18, 2); // as printed by the bank, account's own currency
            $table->enum('status', ['draft', 'completed'])->default('draft');

            // Snapshot written on completion (never recalculated afterwards)
            $table->decimal('book_balance', 18, 2)->nullable();
            $table->decimal('outstanding_deposits', 18, 2)->nullable();
            $table->decimal('outstanding_payments', 18, 2)->nullable();
            $table->decimal('unbooked_bank_items', 18, 2)->nullable();
            $table->decimal('booked_after_date', 18, 2)->nullable();
            $table->decimal('difference', 18, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->index(['company_id', 'bank_account_id', 'status'], 'brec_account_status_idx');
            $table->index(['company_id', 'statement_date'], 'brec_company_date_idx');
        });

        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('bank_reconciliation_id');
            $table->unsignedBigInteger('journal_entry_line_id');
            $table->unsignedBigInteger('bank_statement_line_id')->nullable(); // null = ticked without a statement line
            $table->decimal('amount', 18, 2);               // signed native amount of the ledger line (+ debit, − credit)
            $table->enum('matched_by', ['auto', 'manual'])->default('manual');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations')->cascadeOnDelete();
            $table->foreign('journal_entry_line_id')->references('id')->on('journal_entry_lines')->cascadeOnDelete();
            $table->foreign('bank_statement_line_id')->references('id')->on('bank_statement_lines')->nullOnDelete();
            $table->unique(['company_id', 'journal_entry_line_id'], 'bri_line_cleared_once');
            $table->index(['bank_reconciliation_id'], 'bri_reconciliation_idx');
            $table->index(['bank_statement_line_id'], 'bri_statement_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statement_lines');
    }
};
