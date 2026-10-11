<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000036_create_opening_balance_tables
//
//  Creates all 6 opening balance tables in one migration.
//
//  ob_sessions:
//    One record per company. Tracks overall posting status.
//    Once posted = true, all opening balance data is locked.
//
//  ob_coa_balances:
//    One row per postable COA account.
//    AR (131xxx) and AP (221xxx) and Inventory (135xxx) are
//    auto-populated from partner invoices and inventory stock.
//    User manually enters all other accounts.
//    Foreign currency accounts carry amount_foreign + rate.
//
//  ob_partner_invoices:
//    Invoice-level AR and AP detail.
//    Each invoice has: amount, previous_collection, net_balance.
//    Foreign currency invoices carry currency_id + exchange_rate.
//    Auto-populates ob_coa_balances for AR/AP accounts.
//
//  ob_notes_receivable:
//    Checks received. Status: in_safe | under_collection.
//    under_collection requires bank_account_id from COA.
//
//  ob_notes_payable:
//    Checks issued. Status: issued | presented.
//
//  ob_inventory_stock:
//    Opening stock per product per location.
//    User enters quantity + total_value.
//    System calculates unit_cost (WAC) = total_value / quantity.
//
//  @layer    Migration
//  @depends  companies, chart_of_accounts, partners,
//            currencies, products, inventory_locations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Opening Balance Session ────────────────────────────
        Schema::create('ob_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->date('as_of_date'); // = company.opening_balance_date
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            // Validation summary — stored after last validate run
            $table->decimal('total_debits', 18, 4)->default(0);
            $table->decimal('total_credits', 18, 4)->default(0);
            $table->boolean('trial_balance_ok')->default(false);
            $table->timestamps();
        });

        // ── 2. COA Opening Balances ───────────────────────────────
        Schema::create('ob_coa_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();

            // debit | credit
            $table->string('entry_side', 6);

            // Amount in functional currency
            $table->decimal('amount', 18, 4)->default(0);

            // Foreign currency fields — for cash/bank in foreign currency
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_foreign', 18, 4)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();

            // auto_populated: true = system filled from partner/inventory
            // false = user entered manually
            $table->boolean('auto_populated')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_id']);
            $table->index(['company_id', 'auto_populated']);
        });

        // ── 3. Partner Opening Invoices (AR + AP) ─────────────────
        Schema::create('ob_partner_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();

            // customer | supplier
            $table->string('partner_type', 10);

            // The AR or AP account this invoice posts to
            // AR: 131001 Local / 131002 Export
            // AP: 221001 or relevant AP account
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // Invoice details
            $table->string('invoice_ref', 100);
            $table->date('invoice_date');
            $table->date('due_date');

            // Amounts — all bcmath enforced
            $table->decimal('invoice_amount', 18, 4);
            $table->decimal('previous_collection', 18, 4)->default(0);
            // net_balance = invoice_amount - previous_collection
            // Stored (not computed) for query performance
            $table->decimal('net_balance', 18, 4);

            // Foreign currency — optional
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_foreign', 18, 4)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'partner_type']);
            $table->index(['company_id', 'account_id']);
        });

        // ── 4. Notes Receivable (Checks Received) ─────────────────
        Schema::create('ob_notes_receivable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();

            // Check details
            $table->string('check_number', 50);
            $table->string('drawee_bank', 150); // Customer's bank
            $table->date('check_date');
            $table->date('due_date'); // Maturity date
            $table->boolean('is_post_dated')->default(false);

            // Amount
            $table->decimal('amount', 18, 4);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_foreign', 18, 4)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();

            // in_safe | under_collection
            $table->string('status', 20)->default('in_safe');

            // Required when status = under_collection
            // Points to a bank account in COA (132 or 133)
            $table->foreignId('bank_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        // ── 5. Notes Payable (Checks Issued) ─────────────────────
        Schema::create('ob_notes_payable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();

            // Check details
            $table->string('check_number', 50);
            $table->string('drawl_bank', 150); // Company's bank (drawer bank)
            $table->date('check_date');
            $table->date('due_date');
            $table->boolean('is_post_dated')->default(false);

            // Amount
            $table->decimal('amount', 18, 4);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_foreign', 18, 4)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();

            // issued | presented
            $table->string('status', 20)->default('issued');

            // Bank account this check will be drawn from
            $table->foreignId('bank_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        // ── 6. Inventory Opening Stock ────────────────────────────
        Schema::create('ob_inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();

            // Primary storage UOM only — no conversion needed
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();

            // User enters quantity + total_value
            // System calculates unit_cost = total_value / quantity
            $table->decimal('quantity', 15, 4);
            $table->decimal('total_value', 18, 4);
            $table->decimal('unit_cost', 15, 6)->default(0); // WAC — auto-calculated

            $table->text('notes')->nullable();
            $table->timestamps();

            // One record per product per location
            $table->unique(['company_id', 'product_id', 'location_id']);
            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ob_inventory_stock');
        Schema::dropIfExists('ob_notes_payable');
        Schema::dropIfExists('ob_notes_receivable');
        Schema::dropIfExists('ob_partner_invoices');
        Schema::dropIfExists('ob_coa_balances');
        Schema::dropIfExists('ob_sessions');
    }
};
