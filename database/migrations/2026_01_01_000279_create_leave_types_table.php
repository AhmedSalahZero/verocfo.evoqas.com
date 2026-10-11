<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000279_create_leave_types_table
//
//  Leave Management, part 1 of 2. Company-configurable list of the
//  kinds of leave an employee can request — Annual, Sick, Unpaid,
//  Maternity, and whatever else a company runs. Same "seeded-nothing,
//  fully per-company" lookup shape as deduction_reasons (000227) and
//  product_categories.
//
//  TWO FIELDS CARRY REAL LOGIC, everything else is labelling:
//
//  is_paid — the only field that reaches money. Paid leave costs the
//    company nothing extra: the employee is on salary either way, so
//    there is no journal entry and no payroll effect. UNPAID leave
//    reduces what the employee EARNED that month, which is exactly
//    the `earned_reduction` behavior deduction_reasons already
//    defines. Migration 000227's own header anticipated this
//    module: "Once full HR eventually adds Leave Management, that
//    module becomes a new SOURCE writing the same generic line
//    shape." This column is what makes a leave type that source.
//
//  annual_entitlement_days — NULL means "not balance-tracked". Sick
//    leave in many companies is approved case by case with no fixed
//    allowance, and Unpaid leave by definition has no entitlement to
//    consume. A number means LeaveRequestService enforces it: the
//    days already approved in the same fiscal year, plus the days
//    being requested, may not exceed it.
//
//  deduction_reason_id — the hand-off point to Payroll for unpaid
//    leave. Set it on an unpaid type and Payroll has an unambiguous
//    reason to attach when the deduction is raised. Deliberately
//    NULLABLE and deliberately NOT auto-wired into PayrollRunService
//    by this migration or its module: writing into the payroll
//    posting path would mean changing existing accounting code,
//    which this work is explicitly not authorised to do. Leave
//    Management therefore EXPOSES unpaid leave (see
//    LeaveRequestService::unpaidLeaveDaysFor()) and leaves the
//    decision to consume it with Payroll. Flagged, not hidden.
//
//  WHAT THIS DELIBERATELY DOES NOT DO — a working-day calendar.
//  There is no public-holiday table, no weekend configuration and no
//  working-calendar concept anywhere in this repository, so there is
//  nothing to derive working days from. Day counts are therefore
//  INCLUSIVE CALENDAR DAYS (see leave_requests.days_count). Inventing
//  a weekend rule would be inventing a business rule; this is
//  recorded as an open question instead.
//
//  @layer    Migration
//  @depends  companies, deduction_reasons, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // Paid leave never reaches the ledger; unpaid leave is what
            // Payroll may raise an earned_reduction deduction for.
            $table->boolean('is_paid')->default(true);

            // NULL = this type is not balance-tracked at all.
            $table->decimal('annual_entitlement_days', 6, 2)->nullable();

            // Hand-off to Payroll for unpaid types. Nullable by design.
            $table->foreignId('deduction_reason_id')->nullable()
                ->constrained('deduction_reasons')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            // One company cannot have two leave types with the same
            // English name — the name is what appears on every request.
            $table->unique(['company_id', 'name_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
