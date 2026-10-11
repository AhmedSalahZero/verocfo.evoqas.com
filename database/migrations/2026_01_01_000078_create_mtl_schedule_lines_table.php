<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000078_create_mtl_schedule_lines_table
//
//  The Loan Schedule Table — confirmed NOT auto-calculated. The user
//  either types each installment row directly or uploads an Excel
//  file, exactly the same mechanism as Leasing's Cheque Schedule
//  (lease_cheques) — no amortization formula lives in this codebase
//  for MTL, by design.
//
//  For 'existing' loans (credit_facilities.entry_type), only the
//  REMAINING unpaid installments going forward are entered here —
//  confirmed by Mahmoud — not the full historical schedule.
//
//  Status is NOT a stored enum here — it's derived at read time by
//  comparing the sum of this line's mtl_settlements against
//  schedule_payment, combined with due_date vs today. See
//  MtlScheduleLine::computedStatus() — Past Due / Partially Paid /
//  Paid / Upcoming, matching the reference image's "Past Due" badge.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtl_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();

            $table->date('due_date');
            $table->decimal('beginning_balance', 15, 2);
            $table->decimal('schedule_payment', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('end_balance', 15, 2);

            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtl_schedule_lines');
    }
};
