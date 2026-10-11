<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000066_create_lg_issuances_table
//
//  Individual issued Letters of Guarantee — the layer we deferred
//  when building the LG Agreement. One agreement (credit_facilities,
//  facility_type = letter_guarantee) → many issuances. But issuances
//  don't all come from an agreement:
//
//    - facility      → draws against an LG Agreement's rate card
//                       (credit_facility_id + facility_lg_type_id set)
//    - cash_cover     → no agreement at all, 100% cash-backed
//                       (credit_facility_id + facility_lg_type_id null)
//    - time_deposit   → no agreement, collateral is a blocked TD
//                       (credit_facility_id + facility_lg_type_id null)
//
//  lg_type (Bid Bond / Final LG / Advanced Payment LG / Performance
//  LG / custom) is stored directly on every row regardless of
//  issuance_type — the index page groups by this, and cash_cover /
//  time_deposit issuances have no facility rate card to read it
//  from. For 'facility' issuances it's copied from facility_lg_types
//  at issuance time (a snapshot — if the facility's rate card
//  changes later, already-issued LGs keep the terms they were
//  issued under).
//
//  Beneficiary rules (confirmed with Mahmoud, enforced in
//  LgIssuanceService, not the database):
//    - Bid Bond                                → Partner type customer OR other
//    - Final / Advanced Payment / Performance  → Partner type customer ONLY,
//                                                  and must have an active
//                                                  Project or Sales Order
//
//  posting_mode is the critical field for accounting:
//    - new_issuance     → real event happening now. Creates
//                          lg_issuance_movements rows (pending GL
//                          postings) for cash cover placement,
//                          contingent liability, commission, fees.
//    - opening_issuance → the LG already existed before the company
//                          started using Vero CFO. No movements
//                          created at all — pure record-keeping,
//                          same principle as the existing Opening
//                          Balance module.
//
//  current_amount vs lg_amount: for Advanced Payment LG, the
//  outstanding guarantee decreases every time the customer partially
//  delivers (see lg_issuance_history, event_type = 'decrease').
//  lg_amount is the original issued amount and never changes;
//  current_amount is what's actually outstanding today and drives
//  every forward commission calculation. For the other 3 types,
//  current_amount always equals lg_amount.
//
//  status is partly computed, partly manual:
//    - running / expired  → auto — end_date >= today or < today
//    - cancelled          → manual action (company returned the LG
//                            to the bank; cash cover reversed)
//  "Renewed" is not a 4th status — a renewed LG is still 'running'
//  (assuming the new end_date is in the future). Whether an issuance
//  has ever been renewed is visible from lg_issuance_history, not
//  from this status column. Flagged to Mahmoud as an interpretation,
//  not an explicit instruction — easy to add a renewed_at marker
//  later if a distinct visual tag turns out to be wanted.
//
//  @layer    Migration
//  @depends  credit_facilities, facility_lg_types, banks, currencies,
//            partners, projects, sales_orders, bank_accounts, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lg_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ── Type & posting mode ─────────────────────────────────
            $table->enum('issuance_type', ['facility', 'cash_cover', 'time_deposit']);
            $table->enum('posting_mode', ['new_issuance', 'opening_issuance'])->default('new_issuance');

            // ── LG Type & Facility linkage (facility type only) ────
            $table->foreignId('credit_facility_id')->nullable()->constrained('credit_facilities')->nullOnDelete();
            $table->foreignId('facility_lg_type_id')->nullable()->constrained('facility_lg_types')->nullOnDelete();
            $table->string('lg_type', 150); // always populated — see header

            // ── Header ──────────────────────────────────────────────
            $table->string('transaction_name', 200);
            $table->foreignId('bank_id')->constrained('banks');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('lg_code', 100)->nullable(); // bank's own reference — user-entered, never generated

            // ── Beneficiary ─────────────────────────────────────────
            $table->foreignId('beneficiary_partner_id')->constrained('partners');
            $table->foreignId('contract_reference_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('purchase_order_so_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->date('purchase_order_date')->nullable();

            // ── Dates & amount ───────────────────────────────────────
            $table->date('issuance_date');
            $table->date('end_date'); // user-entered — duration is computed FROM this, not the other way around
            $table->unsignedSmallInteger('duration_months'); // computed at save time, read-only in UI
            $table->decimal('lg_amount', 15, 2);
            $table->decimal('current_amount', 15, 2); // = lg_amount except for Advanced Payment LG decreases

            // ── Cash cover (facility + cash_cover types only) ───────
            $table->decimal('cash_cover_pct', 5, 2)->nullable();
            $table->decimal('cash_cover_amount', 15, 2)->nullable();

            // ── Commission & fees ────────────────────────────────────
            $table->decimal('commission_rate_pct', 6, 4);
            $table->decimal('commission_amount', 15, 2);
            $table->decimal('min_commission_fee_amount', 15, 2)->default(0);
            $table->decimal('issuance_fee_amount', 15, 2)->default(0);
            $table->enum('commission_interval', ['monthly', 'quarterly', 'semi_annual', 'annual'])->default('quarterly');

            // ── Settlement accounts ──────────────────────────────────
            // cash_cover_account_id: facility type = "Cash Cover From Account";
            //   cash_cover type = the single "Cash & Commission" account (same
            //   id written to both this and fees_commission_account_id below)
            $table->foreignId('cash_cover_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('fees_commission_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // ── Time Deposit collateral (time_deposit type only) ────
            $table->foreignId('time_deposit_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // ── Status & lifecycle ────────────────────────────────────
            $table->enum('status', ['running', 'expired', 'cancelled'])->default('running');

            $table->date('cancelled_at')->nullable();
            $table->foreignId('cancelled_reversal_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->date('liquidated_at')->nullable();
            $table->decimal('liquidation_shortfall_amount', 15, 2)->nullable();
            $table->foreignId('liquidation_shortfall_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->text('comment')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'lg_type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'issuance_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lg_issuances');
    }
};
