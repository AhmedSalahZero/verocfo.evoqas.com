<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000280_create_leave_requests_table
//
//  Leave Management, part 2 of 2. One employee's request to be away
//  between two dates, and its approval trail.
//
//  NO ACCOUNTING. A leave request never posts a journal entry, not
//  even an unpaid one. This is deliberate and it is the whole reason
//  this module could be built under the current authorisation: paid
//  leave costs nothing extra (the salary is already accrued by
//  Payroll), and unpaid leave is a REDUCTION applied by Payroll when
//  the month is run, through the deduction_reasons mechanism that
//  already exists. Leave Management supplies the fact; Payroll owns
//  the money. See LeaveRequestService::unpaidLeaveDaysFor().
//
//  STATUS LIFECYCLE — the one place real rules live:
//
//      draft ──submit──▶ submitted ──approve──▶ approved
//        │                   │                     │
//        │                   └───reject───▶ rejected
//        │                   │
//        └──cancel──▶ cancelled ◀────cancel────────┘
//
//  Only `draft` is editable. `approved` is the only status that
//  consumes entitlement and that Payroll should ever look at, and it
//  can still be cancelled (plans change) — which releases the days
//  back. `rejected` is terminal; the employee raises a new request
//  rather than re-opening a refused one, so the refusal stays on the
//  record.
//
//  days_count is INCLUSIVE CALENDAR DAYS, computed by the service
//  and stored rather than derived on read, because entitlement
//  arithmetic has to be stable even if the definition of a day
//  changes later. From 5 March to 7 March is 3 days, not 2. There is
//  no working-day calendar anywhere in this repository to subtract
//  weekends or public holidays from — see migration 000279's header.
//
//  OVERLAP is rejected at the service layer, not by a database
//  constraint: "no two live requests for the same employee may cover
//  the same day" is a range predicate, and MySQL has no exclusion
//  constraint to express it. The index below exists to make that
//  check cheap.
//
//  employee_payroll_profile_id is a convenience link, nullable
//  because an employee Partner can exist before HR has set up their
//  salary. Entitlement and approval do not depend on it.
//
//  @layer    Migration
//  @depends  companies, partners, leave_types, employee_payroll_profiles, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('request_code', 30);

            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_payroll_profile_id')->nullable()
                ->constrained('employee_payroll_profiles')->nullOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days_count', 6, 2);

            $table->text('reason')->nullable();

            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'cancelled'])
                ->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Document codes are unique per company, never globally —
            // the same correction migration 000162 applied across every
            // other document type in this system.
            $table->unique(['company_id', 'request_code']);

            // Drives the overlap check and the "this employee's leave"
            // listing, which are the two hot queries in this module.
            $table->index(['company_id', 'partner_id', 'status']);
            $table->index(['company_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
