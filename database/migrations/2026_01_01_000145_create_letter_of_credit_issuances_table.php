<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000145_create_letter_of_credit_issuances_table
//
//  Individual issued Letters of Credit — the layer beneath the LC
//  Facility (credit_facilities, facility_type = 'import_lc') and its
//  rate card (facility_lc_types). Ported from CashVero's
//  LetterOfCreditIssuance (see CashVero → Vero CFO Treasury Gap
//  Analysis §1.4 — "LG has a full issuance lifecycle; LC does not").
//  CashVero's Odoo sync and statement-ledger mechanics are NOT
//  ported — VeroCFO is an ERP with a real GL; every real event here
//  posts through JournalEntryService, same pattern as lg_issuances.
//
//  Structural differences from LG, confirmed with Mahmoud:
//    - Only 2 real funding sources: 'facility' and 'cash_cover'.
//      CashVero's "Against CD"/"Against TD" turned out to be facility-
//      level collateral (credit_facilities.lc_security_type =
//      'fully_secured'), not a separate issuance-level source — no
//      issuance-level CD/TD fields needed here at all.
//    - Commission is ONE-TIME at issuance (confirmed different from
//      LG's periodic accrual) — no commission_interval, no
//      next_commission_accrual_date.
//    - financed_by ('bank' | 'self') — the genuinely new dimension.
//      A bank-financed LC lets the bank pay the supplier immediately
//      (a real facility drawdown — the company now owes the BANK,
//      tracked here as bank_debt_outstanding) with repayment via
//      lc_issuance_history (event_type = 'settlement'). A self-
//      financed LC pays the supplier directly from the company's own
//      account with no ongoing debt.
//    - Interest on a bank-financed LC is only known and entered per
//      settlement (confirmed: not knowable at issuance, since it
//      depends on how many days pass until the company actually
//      repays) — no interest_rate snapshot stored here at all.
//    - purchase_order_id + supplier_bill_id (confirmed: full linkage
//      into the Purchase cycle) — supplier_bill_id is only ever set
//      once Mark as Paid runs; the Bill itself is allocated/settled
//      at that moment regardless of financed_by (confirmed with
//      Mahmoud: the supplier is paid — by the bank or by the company
//      — either way the Bill is done from the supplier's side; the
//      company's remaining obligation, if any, is to the BANK, not
//      the supplier, and lives in bank_debt_outstanding instead).
//
//  posting_status ('draft' | 'posted') and status ('running' | 'paid')
//  are two independent dimensions, same split as lg_issuances:
//  posting_status governs whether the form is locked / whether a real
//  journal entry is live; status describes where the LC actually is
//  in its real-world lifecycle. There is no 'cancelled'/'expired' —
//  an LC that's never been paid is simply Set to Draft + Deleted if
//  it was a mistake (confirmed: no separate cancel action exists for
//  LC in CashVero either).
//
//  @layer    Migration
//  @depends  credit_facilities, facility_lc_types, banks, currencies,
//            partners, purchase_orders, supplier_bills, bank_accounts, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_of_credit_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ── Type & posting mode ─────────────────────────────────
            $table->enum('issuance_type', ['facility', 'cash_cover']);
            $table->enum('posting_mode', ['new_issuance', 'opening_issuance'])->default('new_issuance');
            $table->enum('posting_status', ['draft', 'posted'])->default('posted');

            // ── LC Type & Facility linkage (facility type only) ────
            $table->foreignId('credit_facility_id')->nullable()->constrained('credit_facilities')->nullOnDelete();
            $table->foreignId('facility_lc_type_id')->nullable()->constrained('facility_lc_types')->nullOnDelete();
            $table->string('lc_type', 150); // always populated (snapshot) — same pattern as lg_issuances.lg_type

            // ── Header ──────────────────────────────────────────────
            $table->string('transaction_name', 200);
            $table->foreignId('bank_id')->constrained('banks');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('lc_code', 100)->nullable(); // bank's own reference — user-entered

            // ── Supplier & Purchase-cycle linkage ────────────────────
            $table->foreignId('supplier_partner_id')->constrained('partners');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills')->nullOnDelete();

            // ── Dates & amount ───────────────────────────────────────
            $table->date('issuance_date');
            $table->date('due_date'); // LC maturity — user-entered, same "user picks the real date" rule as LG's end_date
            $table->unsignedSmallInteger('duration_days'); // computed from issuance_date/due_date, read-only in UI
            $table->decimal('lc_amount', 15, 2);

            // ── Cash cover (both types) ─────────────────────────────
            $table->decimal('cash_cover_pct', 5, 2)->nullable();
            $table->decimal('cash_cover_amount', 15, 2)->nullable();
            $table->foreignId('cash_cover_currency_id')->nullable()->constrained('currencies')->nullOnDelete();

            // ── Fees & commission (one-time — confirmed different from LG) ──
            $table->decimal('commission_rate_pct', 6, 4);
            $table->decimal('commission_amount', 15, 2);
            $table->decimal('min_commission_fee_amount', 15, 2)->default(0);
            $table->decimal('issuance_fee_amount', 15, 2)->default(0);
            $table->foreignId('commission_fees_currency_id')->nullable()->constrained('currencies')->nullOnDelete();

            // ── Settlement accounts ──────────────────────────────────
            $table->foreignId('cash_cover_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('fees_commission_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // ── Financing (the genuinely new LC dimension) ───────────
            $table->enum('financed_by', ['bank', 'self'])->default('self');
            $table->unsignedSmallInteger('financing_duration_days')->nullable(); // grace period before repayment is due, bank-financed only
            $table->decimal('bank_debt_outstanding', 15, 2)->default(0); // what's still owed to the BANK — bank-financed + paid only; always 0 for self-financed

            // ── Status & lifecycle ────────────────────────────────────
            $table->enum('status', ['running', 'paid'])->default('running');
            $table->date('paid_at')->nullable();
            $table->foreignId('payment_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete(); // self-financed only — which account paid the supplier

            $table->text('comment')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'lc_type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'issuance_type']);
            $table->index(['company_id', 'posting_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_of_credit_issuances');
    }
};
