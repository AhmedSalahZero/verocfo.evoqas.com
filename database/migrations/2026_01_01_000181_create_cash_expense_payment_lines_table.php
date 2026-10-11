<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000181_create_cash_expense_payment_lines_table
//
//  Cash Expense Payment becomes a MULTI-LINE document (August 2026,
//  per Mahmoud) — one payment (one payee, one date, one money type,
//  one source account) can now carry several expense lines, e.g. a
//  single "Transportation" payment with a taxi line, a fuel line, and
//  a parking line, each with its own Expense Account and amount.
//  Structural sibling of Supplier Bill's own line table.
//
//  Confirmed with Mahmoud: FULLY FLEXIBLE — each line picks its own
//  Expense Account AND Cost Center (not one account for the whole
//  payment), exactly like a Supplier Bill line.
//
//  Every field that used to live on cash_expense_payments itself
//  (expense_account_id, cost_center_id, subtotal, vat_rate, vat_amount,
//  withholding_tax_rate, withholding_tax_amount, net_amount, and their
//  _functional twins) moves down here — see migration 000182, which
//  drops them from the parent table. The parent keeps its OWN
//  aggregate copies of subtotal/vat_amount/withholding_tax_amount/
//  net_amount (summed from lines on every save) purely for fast list-
//  page display — same convention Supplier Bill already uses for its
//  own bill-level totals.
//
//  is_deductible (same feature as Supplier Bill migration 000180,
//  same day) — default TRUE. When FALSE, this line's vat_amount folds
//  into this SAME line's own expense_account_id instead of VAT Input
//  (137) — see CashExpensePaymentService::postPaymentTransaction().
//  Withholding Tax is unaffected either way — always computed on this
//  line's own subtotal, before VAT.
//
//  Every index below has an explicit short name — this table's own
//  name is long enough that Laravel's auto-generated index name
//  (table_col1_col2_index) exceeds MySQL's 64-character identifier
//  limit. Confirmed the hard way: the first version of this migration
//  failed on exactly this ("Identifier name
//  '..._company_id_cash_expense_payment_id_index' is too long") —
//  same class of bug already hit twice on migration 000179's table.
//
//  @layer    Migration
//  @depends  cash_expense_payments, chart_of_accounts, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_expense_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('cash_expense_payment_id')
                ->constrained('cash_expense_payments')->cascadeOnDelete();

            $table->string('description', 255)->nullable();
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->decimal('subtotal', 15, 2)->default(0);

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->boolean('is_deductible')->default(true);
            $table->decimal('vat_amount', 15, 2)->default(0);

            $table->decimal('withholding_tax_rate', 5, 2)->default(0);
            $table->decimal('withholding_tax_amount', 15, 2)->default(0);

            // = subtotal + vat_amount - withholding_tax_amount, same
            // arithmetic as before — deductibility only changes WHERE
            // the vat_amount posts, never how much cash actually leaves.
            $table->decimal('net_amount', 15, 2)->default(0);

            $table->decimal('subtotal_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('withholding_tax_amount_functional', 15, 2)->default(0);
            $table->decimal('net_amount_functional', 15, 2)->default(0);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'cash_expense_payment_id'], 'cep_lines_company_payment_idx');
            $table->index(['company_id', 'expense_account_id'], 'cep_lines_company_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_expense_payment_lines');
    }
};