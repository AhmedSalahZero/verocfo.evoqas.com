<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  HrHttpEndpointTest
//
//  The two new HR modules through their real HTTP routes, rather than
//  by calling their services directly.
//
//  This layer matters and nothing else in this suite covers it. A
//  service-level test never exercises the Form Request rules, the
//  route model binding, the module permission middleware, or the
//  tenant guard that stops one company opening another company's
//  records by id. Those are four separate places a request can go
//  wrong before any business logic runs.
//
//  The tenant tests are the important ones. Route model binding
//  resolves a model from a URL id before the active-company context
//  is guaranteed to be in place, and CompanyScope steps aside during
//  that resolution by design — so every action that receives a bound
//  model has to check ownership itself. GuardsTenantBoundModels does
//  that, and these tests are what prove it.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Accounting\ExpenseClaimService;
use App\Services\Accounting\LeaveRequestService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class HrHttpEndpointTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private User $companyUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = (new ErpScenario($this->actor))->createCompany();

        // A company admin, which ModulePermission lets straight
        // through — permission granularity is a separate concern from
        // whether these endpoints work at all.
        $this->companyUser = User::withoutGlobalScopes()->create([
            'name'       => 'HR Manager',
            'email'      => 'hr-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->scenario->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);
    }

    // ── Leave Management ──────────────────────────────────────────

    public function test_the_leave_index_loads(): void
    {
        $this->actingAs($this->companyUser)
            ->get(route('hr.leave.index'))
            ->assertOk();
    }

    public function test_the_leave_types_screen_loads(): void
    {
        $this->actingAs($this->companyUser)
            ->get(route('hr.leave-types.index'))
            ->assertOk();
    }

    public function test_a_leave_type_can_be_created_over_http(): void
    {
        $this->actingAs($this->companyUser)
            ->post(route('hr.leave-types.store'), [
                'name_en'                 => 'Annual Leave',
                'is_paid'                 => true,
                'annual_entitlement_days' => 21,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leave_types', [
            'company_id' => $this->scenario->companyId,
            'name_en'    => 'Annual Leave',
            'is_paid'    => 1,
        ]);
    }

    /** The uniqueness rule is per company, not global. */
    public function test_duplicate_leave_type_names_are_rejected(): void
    {
        $this->scenario->createLeaveType('Annual Leave');

        $this->actingAs($this->companyUser)
            ->post(route('hr.leave-types.store'), [
                'name_en' => 'Annual Leave',
                'is_paid' => true,
            ])
            ->assertSessionHasErrors('name_en');
    }

    public function test_a_leave_request_can_be_created_over_http(): void
    {
        $employee = $this->scenario->createEmployee();
        $type     = $this->scenario->createLeaveType();

        $this->actingAs($this->companyUser)
            ->post(route('hr.leave.store'), [
                'partner_id'    => $employee->id,
                'leave_type_id' => $type->id,
                'from_date'     => '2026-03-05',
                'to_date'       => '2026-03-07',
                'reason'        => 'Family visit',
            ])
            ->assertRedirect(route('hr.leave.index'));

        $this->assertDatabaseHas('leave_requests', [
            'company_id' => $this->scenario->companyId,
            'partner_id' => $employee->id,
            'days_count' => '3.00',
            'status'     => LeaveRequest::STATUS_DRAFT,
        ]);
    }

    /** Backwards dates are caught by the Form Request, not the service. */
    public function test_backwards_dates_are_rejected_by_validation(): void
    {
        $employee = $this->scenario->createEmployee();
        $type     = $this->scenario->createLeaveType();

        $this->actingAs($this->companyUser)
            ->post(route('hr.leave.store'), [
                'partner_id'    => $employee->id,
                'leave_type_id' => $type->id,
                'from_date'     => '2026-03-07',
                'to_date'       => '2026-03-05',
            ])
            ->assertSessionHasErrors('to_date');
    }

    /**
     * A business refusal from the service must surface as a form
     * error, not a 500 — an overlap is a normal thing for a user to
     * do, not an exception.
     */
    public function test_an_overlapping_request_returns_a_form_error(): void
    {
        $employee = $this->scenario->createEmployee();
        $type     = $this->scenario->createLeaveType();
        $service  = app(LeaveRequestService::class);

        $first = $service->create($this->scenario->companyId, [
            'partner_id'    => $employee->id,
            'leave_type_id' => $type->id,
            'from_date'     => '2026-03-05',
            'to_date'       => '2026-03-10',
        ], $this->actor);
        $service->submit($first, $this->actor);

        $this->actingAs($this->companyUser)
            ->post(route('hr.leave.store'), [
                'partner_id'    => $employee->id,
                'leave_type_id' => $type->id,
                'from_date'     => '2026-03-08',
                'to_date'       => '2026-03-12',
            ])
            ->assertSessionHasErrors('from_date');
    }

    public function test_the_leave_lifecycle_works_over_http(): void
    {
        $employee = $this->scenario->createEmployee();
        $type     = $this->scenario->createLeaveType();

        $request = app(LeaveRequestService::class)->create($this->scenario->companyId, [
            'partner_id'    => $employee->id,
            'leave_type_id' => $type->id,
            'from_date'     => '2026-03-05',
            'to_date'       => '2026-03-07',
        ], $this->actor);

        $this->actingAs($this->companyUser)
            ->patch(route('hr.leave.submit', $request->id))->assertRedirect();
        $this->assertSame(LeaveRequest::STATUS_SUBMITTED, $request->fresh()->status);

        $this->actingAs($this->companyUser)
            ->patch(route('hr.leave.approve', $request->id))->assertRedirect();
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);

        $this->actingAs($this->companyUser)
            ->patch(route('hr.leave.cancel', $request->id))->assertRedirect();
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_the_balances_endpoint_returns_json(): void
    {
        $employee = $this->scenario->createEmployee();
        $this->scenario->createLeaveType('Annual Leave', entitlementDays: 21);

        $response = $this->actingAs($this->companyUser)
            ->getJson(route('hr.leave.balances', $employee->id))
            ->assertOk();

        $balances = $response->json('balances');

        $this->assertCount(1, $balances);
        $this->assertSame('Annual Leave', $balances[0]['name_en']);
        $this->assertTrue($balances[0]['tracked']);
        $this->assertSame(0, bccomp('21.00', $balances[0]['remaining'], 2));
    }

    // ── Expense Claims ────────────────────────────────────────────

    public function test_the_expense_claim_index_loads(): void
    {
        $this->actingAs($this->companyUser)
            ->get(route('hr.expense-claims.index'))
            ->assertOk();
    }

    public function test_an_expense_claim_can_be_created_over_http(): void
    {
        $employee = $this->scenario->createEmployee();

        $this->actingAs($this->companyUser)
            ->post(route('hr.expense-claims.store'), [
                'partner_id'    => $employee->id,
                'claim_date'    => '2026-03-31',
                'title'         => 'March travel',
                'currency_id'   => $this->scenario->currencyId,
                'exchange_rate' => 1,
                'lines'         => [[
                    'expense_account_id' => $this->scenario->coaId('543'),
                    'expense_date'       => '2026-03-12',
                    'description'        => 'Taxi',
                    'amount'             => 450,
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('expense_claims', [
            'company_id'   => $this->scenario->companyId,
            'partner_id'   => $employee->id,
            'total_amount' => '450.00',
            'status'       => ExpenseClaim::STATUS_DRAFT,
        ]);
    }

    public function test_a_claim_with_no_lines_is_rejected_by_validation(): void
    {
        $employee = $this->scenario->createEmployee();

        $this->actingAs($this->companyUser)
            ->post(route('hr.expense-claims.store'), [
                'partner_id'    => $employee->id,
                'claim_date'    => '2026-03-31',
                'currency_id'   => $this->scenario->currencyId,
                'exchange_rate' => 1,
                'lines'         => [],
            ])
            ->assertSessionHasErrors('lines');
    }

    public function test_a_zero_amount_line_is_rejected_by_validation(): void
    {
        $employee = $this->scenario->createEmployee();

        $this->actingAs($this->companyUser)
            ->post(route('hr.expense-claims.store'), [
                'partner_id'    => $employee->id,
                'claim_date'    => '2026-03-31',
                'currency_id'   => $this->scenario->currencyId,
                'exchange_rate' => 1,
                'lines'         => [[
                    'expense_account_id' => $this->scenario->coaId('543'),
                    'expense_date'       => '2026-03-12',
                    'description'        => 'Nothing',
                    'amount'             => 0,
                ]],
            ])
            ->assertSessionHasErrors('lines.0.amount');
    }

    public function test_the_claim_lifecycle_works_over_http(): void
    {
        $claim  = $this->makeClaim();
        $safeId = $this->scenario->createCashSafe();

        $this->actingAs($this->companyUser)
            ->patch(route('hr.expense-claims.submit', $claim->id))->assertRedirect();
        $this->assertSame(ExpenseClaim::STATUS_SUBMITTED, $claim->fresh()->status);

        // Audit H3 maker-checker: whoever created or submitted a claim
        // can never approve it, so a second person does the approval.
        $approver = User::withoutGlobalScopes()->create([
            'name'       => 'Finance Approver',
            'email'      => 'approver-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->scenario->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        $this->actingAs($approver)
            ->patch(route('hr.expense-claims.approve', $claim->id))->assertRedirect();
        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->journal_entry_id);

        $this->actingAs($this->companyUser)
            ->patch(route('hr.expense-claims.mark-paid', $claim->id), [
                'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
                'payment_safe_account_id' => $safeId,
                'payment_date'            => '2026-04-05',
            ])
            ->assertRedirect();

        $this->assertSame(ExpenseClaim::STATUS_PAID, $claim->fresh()->status);
        $this->assertMoneyEquals('0.00', $this->glBalance($this->scenario->companyId, '232'));
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /** Paying requires a source; the request refuses without one. */
    public function test_paying_without_a_source_is_rejected_by_validation(): void
    {
        $claim = $this->makeClaim();

        app(ExpenseClaimService::class)->approve(
            app(ExpenseClaimService::class)->submit($claim, $this->actor),
            $this->actor
        );

        $this->actingAs($this->companyUser)
            ->patch(route('hr.expense-claims.mark-paid', $claim->id), [
                'payment_source_type' => ExpenseClaim::SOURCE_CASH_SAFE,
            ])
            ->assertSessionHasErrors('payment_safe_account_id');
    }

    // ── Tenant isolation through the URL ──────────────────────────

    /**
     * The case route model binding does not cover on its own: a valid
     * id belonging to a different company. Must be a 404, not a 403 —
     * being told "you may not see this" confirms the record exists.
     */
    public function test_another_companys_leave_request_is_not_reachable(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignEmployee = $other->createEmployee();
        $foreignType     = $other->createLeaveType();

        $foreignRequest = app(LeaveRequestService::class)->create($other->companyId, [
            'partner_id'    => $foreignEmployee->id,
            'leave_type_id' => $foreignType->id,
            'from_date'     => '2026-03-05',
            'to_date'       => '2026-03-07',
        ], $this->actor);

        $this->actingAs($this->companyUser)
            ->get(route('hr.leave.edit', $foreignRequest->id))
            ->assertNotFound();

        $this->actingAs($this->companyUser)
            ->patch(route('hr.leave.submit', $foreignRequest->id))
            ->assertNotFound();

        $this->assertSame(
            LeaveRequest::STATUS_DRAFT,
            $foreignRequest->fresh()->status,
            'The other company\'s request must be untouched.'
        );
    }

    public function test_another_companys_expense_claim_is_not_reachable(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignClaim = $this->makeClaim($other);

        $this->actingAs($this->companyUser)
            ->get(route('hr.expense-claims.show', $foreignClaim->id))
            ->assertNotFound();

        $this->actingAs($this->companyUser)
            ->patch(route('hr.expense-claims.submit', $foreignClaim->id))
            ->assertNotFound();

        $this->assertSame(
            ExpenseClaim::STATUS_DRAFT,
            $foreignClaim->fresh()->status,
            'The other company\'s claim must be untouched.'
        );
    }

    public function test_another_companys_leave_type_cannot_be_edited(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignType = $other->createLeaveType('Their Leave');

        $this->actingAs($this->companyUser)
            ->put(route('hr.leave-types.update', $foreignType->id), [
                'name_en' => 'Hijacked',
                'is_paid' => true,
            ])
            ->assertNotFound();

        $this->assertSame('Their Leave', $foreignType->fresh()->name_en);
    }

    /** An unauthenticated request never reaches any of this. */
    public function test_the_endpoints_require_authentication(): void
    {
        $this->get(route('hr.leave.index'))->assertRedirect();
        $this->get(route('hr.expense-claims.index'))->assertRedirect();
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeClaim(?ErpScenario $scenario = null): ExpenseClaim
    {
        $scenario = $scenario ?? $this->scenario;
        $employee = $scenario->createEmployee();

        return app(ExpenseClaimService::class)->create($scenario->companyId, [
            'partner_id'    => $employee->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [[
                'expense_account_id' => $scenario->coaId('543'),
                'expense_date'       => '2026-03-12',
                'description'        => 'Taxi',
                'amount'             => 450,
            ]],
        ], $this->actor);
    }
}
