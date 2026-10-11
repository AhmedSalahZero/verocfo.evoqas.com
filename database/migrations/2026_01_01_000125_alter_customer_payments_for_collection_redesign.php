<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000125_alter_customer_payments_for_collection_redesign
//
//  Customer Collection redesign (July 2026) — see
//  VeroCFO_Customer_Collection_Design_v1_0.md, the authoritative
//  reference for every decision below. This table was originally
//  built as a simpler "Customer Payment" (one currency, one bank
//  account, three payment methods). This migration brings it up to
//  the full design worked out from Mahmoud's six reference
//  screenshots — the GL posting SHAPE doesn't change (still always
//  `Dr [destination] / Cr AR`, still posted in full at post() time,
//  still allocated via the pre-existing, unchanged
//  customer_payment_allocations table — see design doc Section 1),
//  only the fields describing WHAT the destination is and WHY the
//  collection happened get richer.
//
//  ── Dropped ──────────────────────────────────────────────────────
//  bank_account_id  — replaced by the destination_* trio below. A
//                      Cash-in-Safe collection has no bank account at
//                      all, so this can no longer be a single
//                      mandatory FK.
//  payment_method    — replaced by money_type. The old
//                      bank_transfer/cheque/cash three-way split
//                      doesn't match the four real Money Types
//                      (Bank Deposit and Incoming Transfer are
//                      genuinely separate labels even though they
//                      post identically — confirmed by Mahmoud).
//
//  ── Renamed ──────────────────────────────────────────────────────
//  currency_id → receive_currency_id — this table now needs to
//  distinguish the currency of the cash actually received from the
//  currency the invoices being settled are denominated in. The
//  existing `exchange_rate` column keeps its original meaning
//  unchanged (Receive Currency → functional, used for the actual
//  GL posting) — see invoice_to_receive_exchange_rate below for the
//  new, separate rate this design needed.
//
//  ── Added — dual currency (design doc Section 5) ─────────────────
//  invoice_currency_id               — filters which open invoices
//                                       are eligible in Settlement
//                                       Information.
//  invoice_to_receive_exchange_rate  — ONLY between invoice_currency
//                                       and receive_currency, only
//                                       populated when they differ.
//                                       Deliberately separate from
//                                       `exchange_rate` (Receive →
//                                       functional) — these are two
//                                       different conversions used
//                                       for two different purposes
//                                       (settlement-row matching vs.
//                                       the actual GL entry).
//
//  ── Added — money_type + polymorphic destination (Sections 3, 4) ──
//  money_type                    — bank_deposit / incoming_transfer /
//                                   cash_in_safe / cheque.
//  destination_type              — bank_account / credit_facility.
//                                   NULL for cash_in_safe (uses
//                                   safe_account_id instead) and for
//                                   cheque AT RECEIPT (the cheque's
//                                   own eventual collection
//                                   destination lives on
//                                   cheques_receivable, set later —
//                                   see migration 000126).
//  destination_bank_account_id   — set when destination_type =
//                                   bank_account. Posts to
//                                   BankAccount::coa_account_id.
//  destination_credit_facility_id— set when destination_type =
//                                   credit_facility (an Overdraft —
//                                   confirmed by Mahmoud as a valid
//                                   deposit target, paying the OD
//                                   balance down directly). Posts to
//                                   CreditFacility::coa_account_id.
//                                   Two nullable FKs plus a type
//                                   enum, not one loosely-typed
//                                   polymorphic id column — matches
//                                   this codebase's established
//                                   preference for real FK
//                                   constraints over weak
//                                   polymorphism (see e.g.
//                                   lg_issuance_movements'
//                                   debit_bank_account_id /
//                                   credit_bank_account_id pattern).
//
//  ── Added — Cash in Safe (Section 3) ──────────────────────────────
//  safe_account_id   — the specific 131xxx Cash sub-account picked
//                       (Safes have no separate operational table in
//                       this app, confirmed by Mahmoud — a Safe IS a
//                       Chart of Accounts sub-account under 131, same
//                       principle as a Bank Current Account's own
//                       132xxx sub-account, just without a
//                       bank_accounts-style companion row). The
//                       "Receiving Branch" dropdown in the UI lists
//                       these 131xxx rows directly.
//  receipt_number     — free text, Cash in Safe only.
//
//  ── Added — Down Payment tagging (Sections 1, 2.6) ────────────────
//  collection_type            — settlement / down_payment. Per the
//                                design doc's core insight, this does
//                                NOT change the journal entry shape —
//                                both post `Dr destination / Cr AR`
//                                identically. The only difference is
//                                whether the AR credit is attributed
//                                to invoice(s) immediately
//                                (settlement, via the pre-existing
//                                allocate() action) or left
//                                unallocated with a Contract/SO tag
//                                for later (down_payment).
//  down_payment_project_id    — "Contract" in the UI — confirmed by
//                                Mahmoud to be the same entity as
//                                Vero CFO's existing Project. Nullable
//                                — null means "General Down Payment".
//  down_payment_sales_order_id— Standalone SO tag, nullable,
//                                independent of the project tag.
//
//  @layer    Migration
//  @depends  customer_payments, currencies, bank_accounts,
//            credit_facilities, chart_of_accounts, projects,
//            sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropColumn(['bank_account_id', 'payment_method']);
            $table->renameColumn('currency_id', 'receive_currency_id');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            // Dual currency
            $table->foreignId('invoice_currency_id')->nullable()->after('receive_currency_id')
                ->constrained('currencies')->nullOnDelete();
            $table->decimal('invoice_to_receive_exchange_rate', 15, 6)->nullable()->after('invoice_currency_id');

            // Collection type + down payment tagging
            $table->enum('collection_type', ['settlement', 'down_payment'])->default('settlement')->after('partner_id');
            $table->foreignId('down_payment_project_id')->nullable()
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('down_payment_sales_order_id')->nullable()
                ->constrained('sales_orders')->nullOnDelete();

            // Money type (replaces payment_method)
            $table->enum('money_type', ['bank_deposit', 'incoming_transfer', 'cash_in_safe', 'cheque'])
                ->default('bank_deposit')->after('payment_date');

            // Polymorphic destination — Current Account or Overdraft Facility
            $table->enum('destination_type', ['bank_account', 'credit_facility'])->nullable();
            $table->foreignId('destination_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('destination_credit_facility_id')->nullable()
                ->constrained('credit_facilities')->nullOnDelete();

            // Cash in Safe
            $table->foreignId('safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->index(['company_id', 'money_type']);
            $table->index(['company_id', 'collection_type']);
            $table->index(['company_id', 'down_payment_project_id'], 'cp_company_dp_project_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_currency_id']);
            $table->dropForeign(['down_payment_project_id']);
            $table->dropForeign(['down_payment_sales_order_id']);
            $table->dropForeign(['destination_bank_account_id']);
            $table->dropForeign(['destination_credit_facility_id']);
            $table->dropForeign(['safe_account_id']);

            $table->dropColumn([
                'invoice_currency_id', 'invoice_to_receive_exchange_rate',
                'collection_type', 'down_payment_project_id', 'down_payment_sales_order_id',
                'money_type', 'destination_type', 'destination_bank_account_id',
                'destination_credit_facility_id', 'safe_account_id', 'receipt_number',
            ]);

            $table->renameColumn('receive_currency_id', 'currency_id');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');
            $table->enum('payment_method', ['bank_transfer', 'cheque', 'cash'])->default('bank_transfer');
        });
    }
};
