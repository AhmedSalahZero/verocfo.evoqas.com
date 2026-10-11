<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000039_create_sales_orders_table
//
//  Sales Orders — mandatory gateway to all customer invoicing.
//  Every invoice in Vero CFO must link to a Sales Order.
//  SO can be standalone (project_id nullable) or under a Project.
//
//  SO code format: SO/YYYY/MM/00001 (5-digit sequential per company)
//  SO code is immutable after creation.
//
//  @layer    Migration
//  @depends  companies, projects, partners, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('so_code', 30)->unique();
            $table->unsignedBigInteger('project_id')->nullable();  // null = standalone SO
            $table->unsignedBigInteger('partner_id');              // Customer
            $table->string('subject', 300)->nullable();            // SO title / subject
            $table->text('description')->nullable();
            $table->decimal('so_value', 15, 2)->default(0);
            $table->decimal('invoiced_amount', 15, 2)->default(0); // auto-updated on invoice post
            $table->unsignedBigInteger('currency_id');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->enum('status', ['draft', 'active', 'on_hold', 'closed', 'cancelled'])->default('draft');
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['company_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'end_date']);
            $table->index(['company_id', 'so_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
