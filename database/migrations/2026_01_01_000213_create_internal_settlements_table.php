<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000213_create_internal_settlements_table
//
//  Internal Settlement (per Mahmoud, September 2026) — for a partner
//  who is BOTH a customer and a supplier at once (Partner::types
//  already supports this — see BelongsToCompany/Partner). Nets what
//  they owe us (AR) against what we owe them (AP), in ONE currency
//  only (Mahmoud: no cross-currency netting) — no cash changes
//  hands at all.
//
//  amount — the agreed settlement amount, in the partner's own
//  currency for this settlement (same currency on both the customer
//  and supplier side, by design — see currency_id below). Capped
//  client- and server-side at the smaller of the two open balances.
//
//  Posts Dr Accounts Payable / Cr Accounts Receivable — see
//  InternalSettlementService::postSettlementTransaction() for the
//  full mechanics, including the Realized FX Gain/Loss line that
//  covers any mismatch between what each side's own documents were
//  originally booked at.
//
//  ONE ATOMIC ACTION, same as Customer Collection / Supplier Payment
//  — record() posts immediately, no draft-then-post two-step. 'draft'
//  is only ever reached by reopening a posted settlement
//  (setToDraft()).
//
//  Settlement code format: IS/YYYY/MM/00001 — immutable after creation.
//
//  @layer    Migration
//  @depends  companies, partners, currencies, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('settlement_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners'); // must carry BOTH customer and supplier types

            $table->date('settlement_date');

            $table->foreignId('currency_id')->constrained('currencies'); // same currency on both sides — no cross-currency netting
            $table->decimal('amount', 15, 2); // the agreed settlement amount, in that currency

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_settlements');
    }
};
