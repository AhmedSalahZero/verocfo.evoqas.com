<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000216_create_facility_settlements_table
//
//  Facility Settlement Engine (Building Bible Rule 34) — HEADER row.
//  One row per credit event applied against an OD-family facility
//  (Clean OD / Commercial Paper OD / Contract-Assignment OD / Fully
//  Secured OD — CreditFacility::isOdFamily()).
//
//  IMPORTANT — this table does NOT post its own journal entry.
//  By the time a settlement is recorded, the real cash movement has
//  already been journaled by whichever module triggered it:
//    - CustomerPaymentService::record()       (destination = facility)
//    - InternalMoneyTransferService           (a leg = facility)
//  journal_entry_id below POINTS AT that already-posted entry — it
//  is never a second entry for the same money. The only settlement-
//  engine action that posts its own NEW journal entry is charge
//  posting (FacilityLiabilityChargeService — interest/HDB/admin),
//  because nothing else creates that liability event.
//
//  Rule 34.2 priority order (interest → HDB → admin → withdrawals,
//  oldest due date first) is applied by FacilitySettlementEngine at
//  the moment this row is created, and recorded line-by-line in
//  facility_settlement_allocations (migration 000217) — never here,
//  since one settlement can span several buckets.
//
//  Reversal (Rule 34.5) mirrors the existing Lease wear-down pattern
//  (Rule 29.7): never delete, flip status to 'reversed'. Reversing a
//  settlement does NOT reverse the underlying Customer Payment /
//  Internal Transfer's own journal entry — that stays the caller's
//  responsibility (its own cancel()/reverse() calls into this
//  engine to unwind the allocation side first).
//
//  Rule 34.7 — bank_account_id is mandatory on every settlement row,
//  same as facility_withdrawals / facility_liability_charges.
//
//  @layer    Migration
//  @depends  credit_facilities, bank_accounts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts');

            $table->date('settlement_date');
            $table->decimal('amount', 15, 2); // total credit applied this event

            // What caused this settlement — never a manual/standalone
            // entry point (confirmed with Mahmoud): every settlement
            // is traced back to the real transaction that moved the
            // money.
            $table->enum('source_type', ['customer_payment', 'internal_money_transfer']);
            $table->unsignedBigInteger('source_id');

            // The journal entry the SOURCE already posted — never a
            // new one created by this table. See header note above.
            $table->foreignId('journal_entry_id')->constrained();

            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id'], 'fs_company_facility');
            $table->index(['company_id', 'credit_facility_id', 'status'], 'fs_company_facility_status');
            $table->index(['company_id', 'source_type', 'source_id'], 'fs_company_source');
            $table->index(['company_id', 'settlement_date'], 'fs_company_date');
        });
    }

    public function down(): void { Schema::dropIfExists('facility_settlements'); }
};
