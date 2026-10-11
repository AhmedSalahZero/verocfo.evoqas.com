<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000309_create_customer_credit_balances
//
//  Audit M-9 (Oct 2026) — crediting an invoice that is already paid.
//
//  When a Credit Note is worth more than what the invoice still owes
//  (typically because the invoice was already paid), the surplus is no
//  longer refused: it becomes a CUSTOMER CREDIT BALANCE — money the
//  company owes the customer, held in 235 (the same liability account
//  that holds down payments). It is then either
//    • applied to one of the customer's open invoices, or
//    • refunded in cash (safe / bank account).
//
//  customer_credit_balances        one row per Credit Note surplus
//  customer_credit_applications    each use of it against an invoice
//  customer_refunds                each cash refund paid out of it
//  credit_notes.credit_balance_functional
//                                  the part of the note that went to
//                                  the balance instead of the invoice
//
//  Everything is held in the company's functional currency.
//
//  @layer    Migration
//  @depends  credit_notes, customer_invoices, partners, journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable: MySQL cannot roll back DDL, so a half-finished earlier
        // attempt is cleaned up first (these tables are new — nothing to lose).
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('customer_credit_applications');
        Schema::dropIfExists('customer_credit_balances');

        if (! Schema::hasColumn('credit_notes', 'credit_balance_functional')) {
            Schema::table('credit_notes', function (Blueprint $table) {
                $table->decimal('credit_balance_functional', 15, 2)->default(0)->after('credit_value_functional');
            });
        }

        Schema::create('customer_credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('balance_code', 30);
            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('credit_note_id')->constrained('credit_notes');
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices');
            $table->date('credit_date');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('original_amount', 15, 2);   // functional
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2);

            // Withholding tax the customer already withheld on the paid
            // invoice, in proportion to this surplus. Given back
            // (proportionally) when the surplus is refunded in cash.
            $table->decimal('wht_portion', 15, 2)->default(0);
            $table->decimal('wht_remaining', 15, 2)->default(0);

            $table->enum('status', ['open', 'closed', 'reversed'])->default('open');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'balance_code']);
            $table->unique('credit_note_id');
            $table->index(['company_id', 'partner_id', 'status']);
        });

        Schema::create('customer_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_credit_balance_id')->constrained('customer_credit_balances');
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices');
            $table->date('application_date');
            $table->decimal('amount', 15, 2);            // functional
            $table->decimal('wht_released', 15, 2)->default(0);
            $table->enum('status', ['applied', 'reversed'])->default('applied');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_invoice_id', 'status'], 'cca_company_invoice_status_idx');
        });

        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('refund_code', 30);
            $table->foreignId('customer_credit_balance_id')->constrained('customer_credit_balances');
            $table->foreignId('partner_id')->constrained('partners');
            $table->date('refund_date');
            $table->decimal('amount', 15, 2);             // settled out of the balance (functional)
            $table->decimal('wht_adjustment', 15, 2)->default(0); // given up from 142, not paid in cash
            $table->decimal('cash_amount', 15, 2);        // amount − wht_adjustment, paid out
            $table->string('source_type', 20);            // cash_safe | bank_account
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('safe_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'refund_code']);
            $table->index(['company_id', 'partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('customer_credit_applications');
        Schema::dropIfExists('customer_credit_balances');

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('credit_balance_functional');
        });
    }
};
