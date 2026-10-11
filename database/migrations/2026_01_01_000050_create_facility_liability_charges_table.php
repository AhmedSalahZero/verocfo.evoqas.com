<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000050_create_facility_liability_charges_table
//
//  Non-cash charges that increase the OD liability:
//    - Monthly interest (Dr 555 / Cr 223001)
//    - Highest Debit Balance fee (Dr 552 / Cr 223001)
//    - Admin fees (Dr 552 / Cr 223001)
//
//  System calculates theoretical amount using 360-day convention.
//  User verifies against bank statement and can override.
//  Difference is flagged if > 1% of theoretical.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_liability_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('credit_facility_id');
            $table->enum('charge_type', ['interest', 'highest_debit_balance', 'admin_fee']);
            $table->string('period_label', 20);                    // e.g. "2026-03" or "Q1-2026"
            $table->date('charge_date');
            $table->decimal('theoretical_amount', 15, 2);          // system-calculated (360-day)
            $table->decimal('actual_amount', 15, 2);               // bank statement figure (user confirms/overrides)
            $table->decimal('difference_amount', 15, 2)->default(0); // actual - theoretical
            $table->boolean('user_overridden')->default(false);     // true if user changed theoretical
            $table->text('override_reason')->nullable();
            $table->enum('status', ['pending', 'posted'])->default('pending');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id'],          'flc_company_facility');
            $table->index(['company_id', 'credit_facility_id', 'status'],'flc_company_facility_status');
            $table->index(['company_id', 'charge_date'],                 'flc_company_date');
        });
    }

    public function down(): void { Schema::dropIfExists('facility_liability_charges'); }
};
