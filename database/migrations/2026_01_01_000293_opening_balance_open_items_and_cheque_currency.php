<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000293 — opening balances become real open items;
//  cheques remember their currency and booked functional value
//
//  AUDIT C7 (Sept 2026). Posting opening balances used to produce
//  ONE journal entry and nothing else. The detail behind it — old
//  customer invoices, old supplier invoices, cheques in hand, cheques
//  issued, opening stock — never became anything the rest of the
//  system could work with. This migration adds the columns needed
//  to create those open items at posting time:
//
//    customer_invoices / supplier_bills
//      • is_opening_balance  — true for an item created from the
//        Opening Balances screen. Such an item has no lines and no
//        sales/purchase order, is born 'posted', and can be paid,
//        aged and statemented like any other — but never edited,
//        reopened or cancelled (see the services).
//      • ob_partner_invoice_id — the opening-balance row it came
//        from (unique: one open item per row, so re-running the
//        creation can never duplicate it).
//      • customer_invoices.sales_order_id becomes NULLABLE — only an
//        opening-balance invoice may leave it empty (Rule 28.1 still
//        applies to every normal invoice; the Form Request keeps it
//        required).
//
//    cheques_receivable
//      • ob_note_receivable_id — source opening-balance cheque.
//      • drawee_bank_id NULLABLE + drawee_bank_name — opening cheques
//        only carry the bank's name as text.
//
//    cheques_payable
//      • ob_note_payable_id — source opening-balance cheque.
//      • ob_source_account_id — the bank GL account an opening cheque
//        is drawn on (there is no parent payment to read it from).
//
//  AUDIT C8 (Sept 2026), same tables:
//    cheques_receivable / cheques_payable
//      • currency_id, exchange_rate, amount_functional — the cheque's
//        own currency and the functional (EGP) value it was booked
//        into 134 / 222 at. Collecting or paying a USD cheque must
//        clear 134 / 222 at THIS value, and value the bank side at
//        the clearing-date rate, with the difference to realised FX
//        gain/loss. Existing rows are left NULL; the models fall back
//        to the parent payment's own currency and rate.
//
//  Additive only — no existing data is changed.
//
//  @layer    Migration
//  @depends  customer_invoices, supplier_bills, cheques_receivable,
//            cheques_payable, ob_partner_invoices, ob_notes_receivable,
//            ob_notes_payable, currencies, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Customer invoices ─────────────────────────────────────
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
        });
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->change();
            $table->foreign('sales_order_id')->references('id')->on('sales_orders');

            $table->boolean('is_opening_balance')->default(false)->after('status');
            $table->foreignId('ob_partner_invoice_id')->nullable()->after('is_opening_balance')
                ->constrained('ob_partner_invoices')->nullOnDelete();
            $table->unique('ob_partner_invoice_id', 'ci_ob_partner_invoice_unique');
        });

        // ── Supplier bills ────────────────────────────────────────
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('status');
            $table->foreignId('ob_partner_invoice_id')->nullable()->after('is_opening_balance')
                ->constrained('ob_partner_invoices')->nullOnDelete();
            $table->unique('ob_partner_invoice_id', 'sb_ob_partner_invoice_unique');
        });

        // ── Cheques receivable ────────────────────────────────────
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->dropForeign(['drawee_bank_id']);
        });
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->foreignId('drawee_bank_id')->nullable()->change();
            $table->foreign('drawee_bank_id')->references('id')->on('banks');
            $table->string('drawee_bank_name', 150)->nullable()->after('drawee_bank_id');

            $table->foreignId('ob_note_receivable_id')->nullable()->after('other_collection_id')
                ->constrained('ob_notes_receivable')->nullOnDelete();
            $table->unique('ob_note_receivable_id', 'cheq_recv_ob_note_unique');

            $table->foreignId('currency_id')->nullable()->after('cheque_amount')
                ->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('currency_id');
            $table->decimal('amount_functional', 15, 2)->nullable()->after('exchange_rate');
        });

        // ── Cheques payable ───────────────────────────────────────
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->foreignId('ob_note_payable_id')->nullable()->after('other_payment_id')
                ->constrained('ob_notes_payable')->nullOnDelete();
            $table->unique('ob_note_payable_id', 'cheq_pay_ob_note_unique');
            $table->foreignId('ob_source_account_id')->nullable()->after('ob_note_payable_id')
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->foreignId('currency_id')->nullable()->after('cheque_amount')
                ->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('currency_id');
            $table->decimal('amount_functional', 15, 2)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropUnique('cheq_pay_ob_note_unique');
            $table->dropConstrainedForeignId('ob_note_payable_id');
            $table->dropConstrainedForeignId('ob_source_account_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['exchange_rate', 'amount_functional']);
        });

        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->dropUnique('cheq_recv_ob_note_unique');
            $table->dropConstrainedForeignId('ob_note_receivable_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['exchange_rate', 'amount_functional', 'drawee_bank_name']);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropUnique('sb_ob_partner_invoice_unique');
            $table->dropConstrainedForeignId('ob_partner_invoice_id');
            $table->dropColumn('is_opening_balance');
        });

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropUnique('ci_ob_partner_invoice_unique');
            $table->dropConstrainedForeignId('ob_partner_invoice_id');
            $table->dropColumn('is_opening_balance');
        });
        // sales_order_id / drawee_bank_id are left nullable on rollback:
        // making them NOT NULL again would fail if opening items exist.
    }
};
