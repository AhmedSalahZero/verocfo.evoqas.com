<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_buy_sell_currencies_table
//
//  Buy/Sell Currency — a currency conversion (realization event, per
//  IAS 21). Same four-leg shape as Internal Money Transfer (Bank or
//  Safe on each side — a 131xxx COA sub-account for Safe, no separate
//  Safe/Branch entity), but here the currency SOLD differs from the
//  currency BOUGHT, and a bank's negotiated deal rate almost never
//  equals the day's system reference rate. That spread is a real,
//  immediately-recognized realized FX gain (431) or loss (571) — see
//  BuySellCurrencyService header for the full posting mechanism.
//
//  Functional pattern re-architected from CashVero's own
//  buy_or_sell_currencies table (see CashVero → Vero CFO Treasury Gap
//  Analysis) — the four-leg-shape UX is worth keeping; CashVero has
//  no GL and no reference-rate/gain-loss concept at all, so the
//  entire accounting layer here (reference-rate valuation on both
//  legs, the FX plug, journal_entry_id/status lifecycle, bcmath,
//  company scoping) is new, not a schema copy.
//
//  @layer    Migration
//  @depends  companies, chart_of_accounts, bank_accounts, currencies,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buy_sell_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('transaction_code', 30);
            $table->date('transaction_date');

            // Each leg is Bank OR Safe — exactly one of the pair is
            // set per side, enforced in BuySellCurrencyService, not
            // here. Same shape as internal_money_transfers.
            $table->string('from_leg_type');   // 'bank' | 'safe' — the SELL side
            $table->foreignId('from_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('from_safe_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('to_leg_type');     // 'bank' | 'safe' — the BUY side
            $table->foreignId('to_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('to_safe_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            // The currency leaving the business (sell leg) and the
            // currency entering it (buy leg) — always different
            // currencies; that's what makes this a conversion rather
            // than a same-currency Internal Money Transfer.
            $table->foreignId('currency_to_sell_id')->constrained('currencies');
            $table->decimal('currency_to_sell_amount', 18, 2);

            $table->foreignId('currency_to_buy_id')->constrained('currencies');
            $table->decimal('currency_to_buy_amount', 18, 2);

            // The bank's negotiated deal rate — currency_to_buy per 1
            // unit of currency_to_sell. Used only to compute how much
            // of the buy currency actually lands in the destination
            // account; NOT used for the fair-value/gain-loss plug.
            $table->decimal('deal_rate', 20, 10);

            // System reference rates on transaction_date, functional-
            // currency equivalent, one per leg — auto-pulled from
            // ExchangeRate::rateFor() and shown editable (user can
            // override if their source differs). These are what the
            // realized FX gain/loss plug is actually computed from,
            // per-leg, independently — see service header.
            $table->decimal('sell_reference_rate', 20, 10);
            $table->decimal('buy_reference_rate', 20, 10);

            // Fair value given up vs. fair value received, in
            // functional currency — bcmath outputs of the two
            // reference-rate valuations above.
            $table->decimal('sell_fair_value_functional', 18, 2);
            $table->decimal('buy_fair_value_functional', 18, 2);

            // The plug: positive = realized gain (431), negative =
            // realized loss (571). Signed so the service never needs
            // to re-derive which account to post to from raw
            // comparison logic more than once.
            $table->decimal('realized_fx_gain_loss', 18, 2);

            // draft = saved, nothing posted yet (no cash-ledger
            // movement, no journal entry, no FX plug). posted = real
            // GL impact exists. Editing a posted transaction goes
            // through setToDraft() first — same reverse-then-repost
            // pattern as Customer Payment / Supplier Payment /
            // Internal Money Transfer, never a silent edit.
            $table->string('status')->default('draft');

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->text('comment')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'transaction_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buy_sell_currencies');
    }
};
