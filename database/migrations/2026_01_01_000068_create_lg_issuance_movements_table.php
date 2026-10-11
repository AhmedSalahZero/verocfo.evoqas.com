<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000068_create_lg_issuance_movements_table
//
//  The pending-GL-posting queue for Letter of Guarantee. Extends the
//  same principle already established for Clean OD's
//  facility_withdrawals / facility_liability_charges: the Accounting
//  Engine (journal_entries / General Ledger) doesn't exist yet, but
//  real financial events are happening today (cash actually leaves
//  a current account into cash cover; a contingent liability is
//  actually created) and need to be tracked correctly NOW.
//
//  Difference from the OD pattern: OD facilities don't post to a GL
//  today because the utilization layer that would trigger a posting
//  (supplier payment, collection) hasn't been built yet — nothing
//  has happened, so there's nothing to post. LG Issuance is
//  different: an actual event occurs at Save time, every time. So
//  every 'new_issuance' (never 'opening_issuance' — see lg_issuances
//  header) writes rows here immediately.
//
//  Each row is a staged, ready-to-post journal LINE (not a full
//  entry — a 2-row pair together forms one balanced entry, matching
//  how a real Accounting Engine will store journal_entry_lines).
//  Either side of a movement can hit a Chart of Accounts code
//  (227 contingent liability, 138001 cash cover, 553 LG fees) or an
//  actual BankAccount record (132xxx current account, a blocked TD)
//  — hence two nullable pairs rather than one generic "account".
//
//  When the Accounting Engine's General Ledger is built, a single
//  sweep routine reads every 'pending_gl_posting' row across every
//  FI module (this table plus the OD tables, once THEY need it) and
//  posts them as real journal entries in one pass — nothing about
//  this table's shape needs to change when that day comes.
//
//  @layer    Migration
//  @depends  lg_issuances, chart_of_accounts, bank_accounts,
//            currencies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lg_issuance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lg_issuance_id')->constrained('lg_issuances')->cascadeOnDelete();

            $table->enum('movement_type', [
                'cash_cover_placement',        // Dr 138001 / Cr current account (or Cr COA 227 leg — see service)
                'contingent_liability',        // Dr 138 (or memo) / Cr 227
                'commission_accrual',          // Dr 553 / Cr current account, recurring per commission_interval
                'issuance_fee',                // Dr 553 / Cr current account, one-time
                'renewal_fee',                 // Dr 553 / Cr current account, one-time per renewal
                'cash_cover_release_cancel',   // Dr current account / Cr 138001 — full reversal
                'cash_cover_release_liquidate',// Dr claim/loss / Cr 138001 — partial or full
                'liquidation_shortfall',       // Dr claim/loss / Cr current account — only if claim > cash cover held
                'decrease_adjustment',         // memo-only, no cash movement — Advanced Payment LG partial delivery
            ]);

            $table->date('movement_date');

            $table->foreignId('debit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('debit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('credit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('description', 300);

            $table->enum('status', ['pending_gl_posting', 'posted'])->default('pending_gl_posting');
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_journal_entry_id')->nullable(); // FK added when journal_entries exists

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'lg_issuance_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lg_issuance_movements');
    }
};
