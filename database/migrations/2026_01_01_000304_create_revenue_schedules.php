<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000304 — spreading service revenue over its period
//
//  AUDIT H18 (Sept 2026), scope confirmed with Mahmoud: annual
//  maintenance contracts are invoiced either monthly (nothing to do)
//  or for the WHOLE contract at once — in that case the revenue must
//  be spread over the contract period (IFRS 15.35: a service delivered
//  over time is revenue over time), not recognised in full on day one.
//  Percentage of completion and retention receivable wait for their
//  own design session.
//
//  customer_invoice_lines.service_period_start / _end — optional, on
//  a SERVICE line only. When the period runs beyond the invoice month,
//  the line is credited to 236 Deferred Revenue on posting and a
//  schedule is created.
//
//  revenue_schedules — one per deferred invoice line: the amount (EGP,
//  at the invoice rate), the period, the revenue account and cost
//  centre it will be released to.
//
//  revenue_schedule_lines — one per calendar month of the period,
//  amount pro-rated by days (the last month absorbs rounding).
//  Released by the monthly run on the month-end (or on the invoice
//  date for months already past when invoiced): Dr 236 / Cr revenue.
//
//  Set-to-Draft of the invoice reverses every released month and
//  cancels the schedule (nothing is deleted).
//
//  @layer    Migration
//  @depends  customer_invoices, customer_invoice_lines, chart_of_accounts,
//            cost_centers, journal_entries
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->date('service_period_start')->nullable()->after('cost_center_id');
            $table->date('service_period_end')->nullable()->after('service_period_start');
        });

        Schema::create('revenue_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices');
            $table->foreignId('customer_invoice_line_id')->constrained('customer_invoice_lines');
            $table->foreignId('revenue_account_id')->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_amount', 15, 2);       // EGP, at the invoice rate
            $table->decimal('released_amount', 15, 2)->default(0);
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['customer_invoice_id']);
        });

        Schema::create('revenue_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('revenue_schedule_id')->constrained('revenue_schedules')->cascadeOnDelete();
            $table->date('period_month');                 // first day of the month
            $table->date('recognition_date');             // month-end, or invoice date if later
            $table->unsignedSmallInteger('days');
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'released', 'cancelled'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'recognition_date'], 'rsl_company_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_schedule_lines');
        Schema::dropIfExists('revenue_schedules');
        Schema::table('customer_invoice_lines', fn (Blueprint $t) => $t->dropColumn(['service_period_start', 'service_period_end']));
    }
};
