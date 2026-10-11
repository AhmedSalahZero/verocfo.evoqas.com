<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000049_create_facility_withdrawals_table
//
//  Tracks every withdrawal (drawdown) against an OD facility.
//  Opening balance rows flagged with is_opening_balance = true.
//  Live withdrawals created by supplier payments / transfers out.
//
//  Settlement priority: bank charges first, then nearest due date.
//  Partial settlement supported — settled_amount < amount.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('credit_facility_id');
            $table->date('withdrawal_date');
            $table->date('due_date');                              // withdrawal_date + settled_max_within_days
            $table->decimal('amount', 15, 2);                     // original withdrawal amount
            $table->decimal('settled_amount', 15, 2)->default(0); // how much has been settled so far
            $table->decimal('remaining_amount', 15, 2);           // amount - settled_amount (bcmath maintained)
            $table->enum('status', ['open', 'partial', 'settled'])->default('open');
            $table->boolean('is_opening_balance')->default(false); // true = entered at contract setup
            $table->text('description')->nullable();               // e.g. "Supplier payment — Invoice #123"
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // set when withdrawal posts
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id'],         'fw_company_facility');
            $table->index(['company_id', 'credit_facility_id', 'status'],'fw_company_facility_status');
            $table->index(['company_id', 'due_date'],                    'fw_company_due');
        });
    }

    public function down(): void { Schema::dropIfExists('facility_withdrawals'); }
};
