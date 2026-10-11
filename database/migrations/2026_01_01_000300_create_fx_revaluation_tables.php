<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000300 — month-end foreign-currency revaluation
//
//  AUDIT H12 (Sept 2026) — IAS 21 requires foreign-currency MONETARY
//  balances (cash, bank, receivables, payables, loans) to be restated
//  at the closing rate at every reporting date, with the difference
//  in profit or loss. Accounts 432 Unrealized FX Gain and 572
//  Unrealized FX Loss existed but nothing ever posted to them, so a
//  USD bank, a USD customer invoice or a EUR loan stayed at its
//  historical rate for ever.
//
//  fx_revaluation_runs — one row per month-end run. A run posts TWO
//  journal entries:
//     • the revaluation, dated the month-end (revaluation_date), and
//     • its automatic reversal, dated the 1st of the next month
//       (reversal_date).
//  The reversal brings every balance back to its booked rate, so a
//  later payment's realised FX gain/loss is still measured against
//  the original booking — the payment modules do not change at all.
//  status: posted | cancelled (a cancelled run is reversed, never
//  deleted — there are no hard deletes on financial data).
//
//  fx_revaluation_lines — the audit trail: one row per revalued item
//  (a bank / loan account, or an open invoice, bill or cheque) with
//  its foreign balance, book value, the closing rate and the
//  difference posted.
//
//  @layer    Migration
//  @depends  companies, currencies, chart_of_accounts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('run_code', 30);
            $table->date('revaluation_date');
            $table->date('reversal_date');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->json('closing_rates');                       // {"USD": "50.250000", …} as used
            $table->decimal('total_gain', 15, 2)->default(0);
            $table->decimal('total_loss', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);    // + gain / − loss
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'run_code']);
            $table->index(['company_id', 'revaluation_date', 'status'], 'fxr_company_date_status_idx');
        });

        Schema::create('fx_revaluation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fx_revaluation_run_id')->constrained('fx_revaluation_runs')->cascadeOnDelete();
            $table->enum('item_type', ['account', 'customer_invoice', 'supplier_bill', 'cheque_receivable', 'cheque_payable']);
            $table->unsignedBigInteger('item_id')->nullable();   // document id; null for 'account'
            $table->string('item_label', 255);
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('native_balance', 18, 2);            // foreign balance, signed debit − credit
            $table->decimal('book_balance', 15, 2);              // EGP book value, signed debit − credit
            $table->decimal('closing_rate', 15, 6);
            $table->decimal('revalued_balance', 15, 2);          // native × closing rate
            $table->decimal('difference', 15, 2);                // revalued − book: + = debit / gain, − = credit / loss
            $table->timestamps();

            $table->index(['fx_revaluation_run_id']);
            $table->index(['company_id', 'item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_lines');
        Schema::dropIfExists('fx_revaluation_runs');
    }
};
