<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000129_create_delivery_notes_table
//
//  Delivery & Revenue Recognition v1.0 — see
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md Section 8.
//  The outbound mirror of Goods Receipt Note: a document that says
//  "these physical units, from this Sales Order, left the warehouse."
//  This is the real IFRS 15 trigger — transfer of control — and is
//  what actually drives COGS/Inventory movement and Revenue
//  recognition (Section 1), regardless of whether a Customer Invoice
//  exists yet for this quantity.
//
//  sales_order_id is mandatory — same "no ad-hoc path" principle as
//  Customer Invoice's own SO requirement (Rule 28).
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Posting is the one irreversible action, same convention as GRN —
//  a posted Delivery Note is never edited; corrections happen through
//  whatever the eventual Sales Return/reversal mechanism turns out to
//  be (not designed here — flagged as a future need, not assumed
//  already solved).
//
//  Delivery code format: DN/YYYY/MM/00001 — immutable after creation.
//
//  @layer    Migration
//  @depends  companies, sales_orders, partners, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('delivery_code', 30)->unique();

            $table->foreignId('sales_order_id')->constrained('sales_orders'); // mandatory — see header
            $table->foreignId('partner_id')->constrained('partners'); // customer

            $table->date('delivery_date'); // drives the journal entry date

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            // Two entries can post per Delivery Note (Dr COGS/Cr Inventory,
            // and Dr 143 or 236/Cr Revenue) — see design doc Section 8.3.
            // Both linked here since neither alone fully represents "what
            // this delivery did."
            $table->foreignId('inventory_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('revenue_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
