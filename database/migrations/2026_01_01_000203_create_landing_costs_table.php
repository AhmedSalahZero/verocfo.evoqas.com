<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000203_create_landing_costs_table
//
//  Landing Cost — see VeroCFO_Landing_Cost_Design_v1_0.md for the
//  full design and decision log. A standalone document representing
//  one logistics cost (customs dues, transportation, etc.) tied to
//  a shipment — deliberately NOT linked to any Purchase Order
//  (confirmed with Mahmoud: always freely picked from a list at
//  Goods Receipt time, regardless of PO).
//
//  Two independent lifecycles happen on this one record:
//
//    LIABILITY  create()  → Dr landing_cost_account_id / Cr 232 Other
//                           Creditors
//               pay()     → Dr 232 Other Creditors / Cr Bank
//               (status: draft → posted → paid; 'draft' exists only
//               so the amount/account can still be corrected before
//               the liability is actually posted)
//
//    ATTACHMENT attachToGrn() → sets goods_receipt_note_id and
//               attached_at, as PART OF that GRN's own posting —
//               this is what actually moves the amount out of
//               146xxx into Inventory. See landing_cost_allocations
//               (migration 000204) for the per-GRN-line split.
//
//  ONE-TIME USE (confirmed with Mahmoud, non-negotiable): once
//  goods_receipt_note_id is set, this record is permanently removed
//  from every future "eligible landing costs" picker — never
//  reusable, never partially attached across more than one GRN, even
//  if in principle some value could be argued to remain.
//
//  partner_id is always a Landing Cost Supplier
//  (partners.is_landing_cost_supplier = true) — enforced in
//  LandingCostService, not the schema, same pattern as every other
//  business rule in this project.
//
//  @layer    Migration
//  @depends  companies, partners, chart_of_accounts, currencies,
//            goods_receipt_notes, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('landing_cost_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners'); // a Landing Cost Supplier
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts'); // 146xxx sub-account

            $table->date('cost_date');
            $table->decimal('amount', 15, 2); // functional currency — no foreign-currency dimension, see header
            $table->string('reference', 100)->nullable(); // supplier's own bill/quotation number

            $table->enum('status', ['draft', 'posted', 'paid', 'cancelled'])->default('draft');

            // ── Liability leg ────────────────────────────────────
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete(); // set when the liability posts (Dr clearing / Cr 232)

            // ── Payment leg — independent of attachment, can happen
            //    before or after the goods physically arrive ───────
            $table->foreignId('payment_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('paid_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->date('paid_date')->nullable();

            // ── Attachment — see header. Once set, permanently used ──
            $table->foreignId('goods_receipt_note_id')->nullable()
                ->constrained('goods_receipt_notes')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            // The "eligible for attachment" query — posted, unpaid-or-paid
            // doesn't matter, but never yet attached — filters on exactly
            // these two columns together.
            $table->index(['company_id', 'status', 'goods_receipt_note_id'], 'landing_costs_eligibility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_costs');
    }
};
