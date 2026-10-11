<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  LeaveManagementTest
//
//  Leave Management, end to end. The module posts no journal entries
//  by design, so what is under test here is the workflow itself: who
//  may move a request between states, what has to be true first, and
//  whether an employee's balance is arithmetically right after a run
//  of approvals and cancellations.
//
//  Every expected balance below is worked out by hand in the test
//  from the entitlement and the days requested, never read back from
//  the service that computed it.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Partner;
use App\Services\Accounting\LeaveRequestService;
use App\Services\Accounting\LeaveTypeService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class LeaveManagementTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private LeaveRequestService $service;
    private Partner $employee;
    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = (new ErpScenario($this->actor))->createCompany();
        $this->service  = app(LeaveRequestService::class);

        $this->employee = $this->scenario->createEmployee('Mona Adel');
        $this->annual   = $this->scenario->createLeaveType('Annual Leave', isPaid: true, entitlementDays: 21);
    }

    private function request(string $from, string $to, ?LeaveType $type = null, ?Partner $who = null): LeaveRequest
    {
        return $this->service->create($this->scenario->companyId, [
            'partner_id'    => ($who ?? $this->employee)->id,
            'leave_type_id' => ($type ?? $this->annual)->id,
            'from_date'     => $from,
            'to_date'       => $to,
            'reason'        => 'Test request',
        ], $this->actor);
    }

    // ── Day counting ──────────────────────────────────────────────

    /**
     * Inclusive calendar days: 5 March to 7 March is three days off,
     * not two. Getting this wrong by one is the single most likely
     * arithmetic bug in a leave module.
     */
    public function test_day_count_is_inclusive_of_both_dates(): void
    {
        $request = $this->request('2026-03-05', '2026-03-07');

        $this->assertSame(0, bccomp('3.00', (string) $request->days_count, 2),
            "Expected 3 days, got {$request->days_count}.");
    }

    public function test_a_single_day_request_counts_as_one_day(): void
    {
        $request = $this->request('2026-03-05', '2026-03-05');

        $this->assertSame(0, bccomp('1.00', (string) $request->days_count, 2));
    }

    public function test_a_request_ending_before_it_starts_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be before its start date/i');

        $this->request('2026-03-07', '2026-03-05');
    }

    // ── Lifecycle ─────────────────────────────────────────────────

    public function test_a_new_request_starts_as_a_draft_with_a_code(): void
    {
        $request = $this->request('2026-03-05', '2026-03-07');

        $this->assertSame(LeaveRequest::STATUS_DRAFT, $request->status);
        $this->assertStringStartsWith('LV/', $request->request_code);
        $this->assertNull($request->approved_at);
    }

    public function test_the_full_happy_path_records_who_did_what(): void
    {
        $request = $this->request('2026-03-05', '2026-03-07');

        $submitted = $this->service->submit($request, $this->actor);
        $this->assertSame(LeaveRequest::STATUS_SUBMITTED, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertSame($this->actor->id, $submitted->submitted_by);

        $approved = $this->service->approve($submitted, $this->actor);
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($this->actor->id, $approved->approved_by);
    }

    public function test_a_rejection_keeps_its_reason_on_the_record(): void
    {
        $request = $this->service->submit($this->request('2026-03-05', '2026-03-07'), $this->actor);

        $rejected = $this->service->reject($request, $this->actor, 'Peak season — please rebook.');

        $this->assertSame(LeaveRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Peak season — please rebook.', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);
    }

    /** Approval is not a state you can jump to from a draft. */
    public function test_a_draft_cannot_be_approved_without_being_submitted(): void
    {
        $request = $this->request('2026-03-05', '2026-03-07');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be approved from status .draft./i');

        $this->service->approve($request, $this->actor);
    }

    public function test_an_approved_request_cannot_be_approved_again(): void
    {
        $request = $this->service->approve(
            $this->service->submit($this->request('2026-03-05', '2026-03-07'), $this->actor),
            $this->actor
        );

        $this->expectException(\DomainException::class);

        $this->service->approve($request, $this->actor);
    }

    /** A refused request is terminal — the employee raises a new one. */
    public function test_a_rejected_request_cannot_be_resurrected(): void
    {
        $rejected = $this->service->reject(
            $this->service->submit($this->request('2026-03-05', '2026-03-07'), $this->actor),
            $this->actor
        );

        $this->expectException(\DomainException::class);

        $this->service->submit($rejected, $this->actor);
    }

    public function test_only_a_draft_can_be_edited(): void
    {
        $request = $this->service->submit($this->request('2026-03-05', '2026-03-07'), $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/can no longer be edited/i');

        $this->service->update($request, [
            'partner_id'    => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date'     => '2026-03-05',
            'to_date'       => '2026-03-09',
        ], $this->actor);
    }

    public function test_editing_a_draft_recalculates_its_day_count(): void
    {
        $request = $this->request('2026-03-05', '2026-03-07');

        $updated = $this->service->update($request, [
            'partner_id'    => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date'     => '2026-03-05',
            'to_date'       => '2026-03-09',
        ], $this->actor);

        $this->assertSame(0, bccomp('5.00', (string) $updated->days_count, 2));
    }

    // ── Overlap ───────────────────────────────────────────────────

    /**
     * Nobody can be on two kinds of leave at once. Tested at the
     * boundary — the second request starts on the last day of the
     * first, which is still an overlap.
     */
    public function test_two_live_requests_cannot_cover_the_same_day(): void
    {
        $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already has leave booked/i');

        $this->request('2026-03-10', '2026-03-12');
    }

    public function test_a_draft_does_not_block_another_request(): void
    {
        // Left as a draft — an intention, not a booking.
        $this->request('2026-03-05', '2026-03-10');

        $second = $this->request('2026-03-08', '2026-03-12');

        $this->assertSame(LeaveRequest::STATUS_DRAFT, $second->status);
    }

    /** Cancelling releases the dates for someone to rebook. */
    public function test_a_cancelled_request_releases_its_dates(): void
    {
        $first = $this->service->approve(
            $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor),
            $this->actor
        );

        $this->service->cancel($first, $this->actor);

        $second = $this->request('2026-03-05', '2026-03-10');

        $this->assertSame(0, bccomp('6.00', (string) $second->days_count, 2));
    }

    public function test_different_employees_may_take_the_same_dates(): void
    {
        $other = $this->scenario->createEmployee('Karim Fouad');

        $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor);
        $second = $this->request('2026-03-05', '2026-03-10', who: $other);

        $this->assertSame($other->id, $second->partner_id);
    }

    // ── Entitlement ───────────────────────────────────────────────

    /**
     * Independent calculation: 21 days entitlement, 6 days approved
     * (5–10 March inclusive) leaves 15.
     */
    public function test_approved_leave_reduces_the_remaining_balance(): void
    {
        $this->service->approve(
            $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor),
            $this->actor
        );

        $balance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $this->annual, '2026-03-01'
        );

        $this->assertTrue($balance['tracked']);
        $this->assertSame(0, bccomp('21.00', $balance['entitlement'], 2));
        $this->assertSame(0, bccomp('6.00', $balance['taken'], 2), "Taken was {$balance['taken']}.");
        $this->assertSame(0, bccomp('15.00', $balance['remaining'], 2), "Remaining was {$balance['remaining']}.");
    }

    /** Only APPROVED leave consumes entitlement — not submitted, not draft. */
    public function test_unapproved_leave_does_not_consume_entitlement(): void
    {
        $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor);

        $balance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $this->annual, '2026-03-01'
        );

        $this->assertSame(0, bccomp('0.00', $balance['taken'], 2));
        $this->assertSame(0, bccomp('21.00', $balance['remaining'], 2));
    }

    public function test_cancelling_approved_leave_returns_the_days(): void
    {
        $request = $this->service->approve(
            $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor),
            $this->actor
        );

        $this->service->cancel($request, $this->actor);

        $balance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $this->annual, '2026-03-01'
        );

        $this->assertSame(0, bccomp('0.00', $balance['taken'], 2));
        $this->assertSame(0, bccomp('21.00', $balance['remaining'], 2),
            'Cancelling approved leave must return the days to the balance.');
    }

    /**
     * 21 days entitlement, 20 already approved, a 3-day request
     * would reach 23 — refused, and the message states what is left.
     */
    public function test_a_request_beyond_the_entitlement_is_refused(): void
    {
        // 1–20 April inclusive = 20 days.
        $this->service->approve(
            $this->service->submit($this->request('2026-04-01', '2026-04-20'), $this->actor),
            $this->actor
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/only 1.00 day\(s\) remain/i');

        $this->request('2026-05-01', '2026-05-03');
    }

    /** Landing exactly on the entitlement is allowed; one more is not. */
    public function test_a_request_that_exactly_exhausts_the_entitlement_is_allowed(): void
    {
        $request = $this->service->approve(
            $this->service->submit($this->request('2026-04-01', '2026-04-21'), $this->actor),
            $this->actor
        );

        $this->assertSame(0, bccomp('21.00', (string) $request->days_count, 2));

        $balance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $this->annual, '2026-04-01'
        );
        $this->assertSame(0, bccomp('0.00', $balance['remaining'], 2));
    }

    /** A type with no entitlement figure is not tracked at all. */
    public function test_an_untracked_leave_type_has_no_limit(): void
    {
        $sick = $this->scenario->createLeaveType('Sick Leave', isPaid: true, entitlementDays: null);

        $request = $this->service->approve(
            $this->service->submit($this->request('2026-06-01', '2026-06-30', type: $sick), $this->actor),
            $this->actor
        );

        $this->assertSame(0, bccomp('30.00', (string) $request->days_count, 2));

        $balance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $sick, '2026-06-01'
        );

        $this->assertFalse($balance['tracked']);
        $this->assertNull($balance['remaining']);
        $this->assertSame(0, bccomp('30.00', $balance['taken'], 2));
    }

    /** Entitlement is per type — annual leave does not eat sick leave. */
    public function test_entitlement_is_tracked_separately_per_leave_type(): void
    {
        $study = $this->scenario->createLeaveType('Study Leave', isPaid: true, entitlementDays: 5);

        $this->service->approve(
            $this->service->submit($this->request('2026-03-02', '2026-03-06', type: $study), $this->actor),
            $this->actor
        );

        $annualBalance = $this->service->balanceFor(
            $this->scenario->companyId, $this->employee->id, $this->annual, '2026-03-01'
        );

        $this->assertSame(0, bccomp('0.00', $annualBalance['taken'], 2),
            'Study leave must not consume the annual entitlement.');
        $this->assertSame(0, bccomp('21.00', $annualBalance['remaining'], 2));
    }

    // ── The payroll seam ──────────────────────────────────────────

    /**
     * The one number this module contributes to money: approved days
     * of UNPAID leave in a period. Paid leave must never appear here,
     * because the salary is accrued regardless.
     */
    public function test_only_approved_unpaid_leave_reaches_the_payroll_figure(): void
    {
        $unpaid = $this->scenario->createLeaveType('Unpaid Leave', isPaid: false, entitlementDays: null);

        // Paid annual leave in the same month — must be ignored.
        $this->service->approve(
            $this->service->submit($this->request('2026-03-02', '2026-03-04'), $this->actor),
            $this->actor
        );

        // Approved unpaid leave — counts.
        $this->service->approve(
            $this->service->submit($this->request('2026-03-10', '2026-03-13', type: $unpaid), $this->actor),
            $this->actor
        );

        // Submitted but not approved unpaid leave — must be ignored.
        $this->service->submit($this->request('2026-03-20', '2026-03-25', type: $unpaid), $this->actor);

        $days = $this->service->unpaidLeaveDaysFor(
            $this->scenario->companyId, $this->employee->id, '2026-03-01', '2026-03-31'
        );

        $this->assertSame(0, bccomp('4.00', $days, 2),
            "Only the approved unpaid request (10-13 March = 4 days) counts; got {$days}.");
    }

    public function test_the_payroll_figure_is_zero_when_nothing_is_unpaid(): void
    {
        $this->service->approve(
            $this->service->submit($this->request('2026-03-02', '2026-03-04'), $this->actor),
            $this->actor
        );

        $this->assertSame(0, bccomp('0.00', $this->service->unpaidLeaveDaysFor(
            $this->scenario->companyId, $this->employee->id, '2026-03-01', '2026-03-31'
        ), 2));
    }

    // ── Guards ────────────────────────────────────────────────────

    public function test_leave_cannot_be_booked_for_a_non_employee(): void
    {
        $this->scenario->createPartners(); // creates a customer and a supplier

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/not registered as an employee/i');

        $this->request('2026-03-05', '2026-03-07', who: $this->scenario->customers[0]);
    }

    public function test_leave_cannot_be_booked_against_another_companys_employee(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignEmployee = $other->createEmployee('Someone Else');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/does not belong to this company/i');

        $this->request('2026-03-05', '2026-03-07', who: $foreignEmployee);
    }

    public function test_leave_cannot_be_booked_against_another_companys_leave_type(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignType = $other->createLeaveType('Foreign Leave');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/does not belong to this company/i');

        $this->request('2026-03-05', '2026-03-07', type: $foreignType);
    }

    public function test_an_inactive_leave_type_cannot_be_used(): void
    {
        app(LeaveTypeService::class)->toggleActive($this->annual, $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no longer active/i');

        $this->request('2026-03-05', '2026-03-07');
    }

    /**
     * Entitlement is tracked per fiscal year, so leave outside every
     * fiscal year has nothing to measure against and is refused
     * rather than silently counted somewhere arbitrary.
     */
    public function test_leave_outside_every_fiscal_year_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no fiscal year covers/i');

        $this->request('2019-03-05', '2019-03-07');
    }

    // ── Leave types ───────────────────────────────────────────────

    public function test_a_used_leave_type_cannot_be_deleted(): void
    {
        $this->request('2026-03-05', '2026-03-07');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be deleted.*[Dd]eactivate it instead/s');

        app(LeaveTypeService::class)->delete($this->annual, $this->actor);
    }

    public function test_an_unused_leave_type_can_be_deleted(): void
    {
        $spare = $this->scenario->createLeaveType('Compassionate Leave');

        app(LeaveTypeService::class)->delete($spare, $this->actor);

        $this->assertDatabaseMissing('leave_types', ['id' => $spare->id]);
    }

    /** A deduction reason is only meaningful on an unpaid type. */
    public function test_a_paid_leave_type_never_keeps_a_deduction_reason(): void
    {
        $reason = DB::table('deduction_reasons')->insertGetId([
            'company_id' => $this->scenario->companyId,
            'name_en'    => 'Unpaid leave',
            'behavior'   => 'earned_reduction',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paid = app(LeaveTypeService::class)->create($this->scenario->companyId, [
            'name_en'             => 'Paid Special Leave',
            'is_paid'             => true,
            'deduction_reason_id' => $reason,
        ], $this->actor);

        $this->assertNull($paid->deduction_reason_id,
            'A paid leave type has nothing for Payroll to deduct, so the link is dropped.');

        $unpaid = app(LeaveTypeService::class)->create($this->scenario->companyId, [
            'name_en'             => 'Unpaid Special Leave',
            'is_paid'             => false,
            'deduction_reason_id' => $reason,
        ], $this->actor);

        $this->assertSame($reason, $unpaid->deduction_reason_id);
    }

    // ── No accounting ─────────────────────────────────────────────

    /**
     * The defining property of this module: a full leave lifecycle
     * must leave the general ledger completely untouched.
     */
    public function test_the_whole_leave_lifecycle_posts_nothing_to_the_ledger(): void
    {
        $unpaid = $this->scenario->createLeaveType('Unpaid Leave', isPaid: false, entitlementDays: null);

        $entriesBefore = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)->count();

        $approved = $this->service->approve(
            $this->service->submit($this->request('2026-03-05', '2026-03-10'), $this->actor), $this->actor
        );
        $this->service->cancel($approved, $this->actor);

        $this->service->reject(
            $this->service->submit($this->request('2026-04-01', '2026-04-03', type: $unpaid), $this->actor),
            $this->actor
        );

        $this->service->approve(
            $this->service->submit($this->request('2026-05-01', '2026-05-05', type: $unpaid), $this->actor),
            $this->actor
        );

        $this->assertSame(
            $entriesBefore,
            DB::table('journal_entries')->where('company_id', $this->scenario->companyId)->count(),
            'Leave Management must never post a journal entry — unpaid leave reaches money '
            .'only through Payroll, via the deduction mechanism.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }
}
