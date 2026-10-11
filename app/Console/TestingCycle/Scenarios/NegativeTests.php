<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  NegativeTests
//
//  What a tester does that a demo script never does: enter the wrong
//  thing on purpose and check that the system says no.
//
//  These run against a company that has been trading for months, not
//  a blank one, because most of these rules cannot be reached
//  otherwise — you cannot test "cannot sell more than you hold"
//  without holding something first, and you cannot test a closed
//  period without a period worth closing.
//
//  TWO KINDS OF NO, and they live in different layers:
//
//    a FORM rule    — the field is missing, negative, malformed, or
//                     points at another company's record. Caught by
//                     the Form Request before any service runs.
//    a BUSINESS rule — the data is well formed but the operation is
//                     not allowed right now. Only the service knows.
//
//  Both are tested, separately, because a system can easily have one
//  and not the other — and a missing form rule is invisible until
//  someone bypasses the UI.
//
//  WHEN A RULE IS MISSING, THAT IS THE FINDING. The cycle never
//  supplies the value the form should have demanded, and never
//  reduces a quantity to avoid an overdraw. If the ERP accepts
//  something it should refuse, the report says so.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Http\Requests\Accounting\ExpenseClaimRequest;
use App\Http\Requests\Accounting\LeaveRequestRequest;
use App\Http\Requests\Accounting\LeaveTypeRequest;
use App\Models\ExpenseClaim;
use App\Services\Accounting\ExpenseClaimService;
use App\Services\Accounting\LeaveRequestService;
use App\Services\Inventory\StockLedgerService;
use Carbon\Carbon;

class NegativeTests
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(CycleContext $ctx, string $archetype, int $year): void
    {
        $this->log->enterPeriod("negative tests {$year}");
        $this->log->note('Negative and edge-case testing.');

        $this->inventoryRules($ctx);
        $this->leaveFormRules($ctx, $year);
        $this->leaveBusinessRules($ctx, $year);
        $this->expenseClaimRules($ctx);
        $this->tenantRules($ctx);
    }

    // ══════════════════════════════════════════════════════════════
    //  Inventory
    // ══════════════════════════════════════════════════════════════

    private function inventoryRules(CycleContext $ctx): void
    {
        $product = $ctx->sellableProducts()[0] ?? null;

        if (! $product) {
            return;
        }

        // Issuing more than exists must be refused, not absorbed into
        // a negative balance.
        $onHand   = $ctx->book->quantity($product->id);
        $tooMuch  = bcadd($onHand, '1000', 4);

        $this->gate->expectRefused(
            fn () => app(StockLedgerService::class)->issue(
                companyId: $ctx->companyId,
                product: $product,
                inventoryLocationId: $ctx->masterLocationId,
                quantity: $tooMuch,
                actor: $ctx->actor,
            ),
            '/insufficient/i',
            'Issue more stock than the company holds',
            'the stock ledger must refuse to go negative'
        );

        // A zero-quantity movement is meaningless and must be refused.
        $this->gate->expectRefused(
            fn () => app(StockLedgerService::class)->issue(
                companyId: $ctx->companyId,
                product: $product,
                inventoryLocationId: $ctx->masterLocationId,
                quantity: '0',
                actor: $ctx->actor,
            ),
            '/quantity/i',
            'Issue a quantity of zero',
            'a movement must have a positive quantity'
        );

        // As must a negative one.
        $this->gate->expectRefused(
            fn () => app(StockLedgerService::class)->receive(
                companyId: $ctx->companyId,
                product: $product,
                inventoryLocationId: $ctx->masterLocationId,
                quantity: '-5',
                unitCost: '10.00',
                actor: $ctx->actor,
            ),
            '/quantity/i',
            'Receive a negative quantity',
            'a receipt must have a positive quantity'
        );

        // Stock must still be clean after all that.
        (new Verify($this->log, $ctx->companyId))
            ->noNegativeStock('After inventory negative tests');
    }

    // ══════════════════════════════════════════════════════════════
    //  Leave — form rules
    // ══════════════════════════════════════════════════════════════

    private function leaveFormRules(CycleContext $ctx, int $year): void
    {
        $employee = $ctx->employees[0];

        $this->gate->expectRejected(
            LeaveRequestRequest::class,
            [
                'partner_id'    => $employee->id,
                'leave_type_id' => $ctx->leaveTypes['annual']->id,
                'from_date'     => "{$year}-07-10",
                'to_date'       => "{$year}-07-05",
            ],
            'to_date',
            'Leave that ends before it starts'
        );

        $this->gate->expectRejected(
            LeaveRequestRequest::class,
            [
                'leave_type_id' => $ctx->leaveTypes['annual']->id,
                'from_date'     => "{$year}-07-05",
                'to_date'       => "{$year}-07-10",
            ],
            'partner_id',
            'Leave with no employee'
        );

        $this->gate->expectRejected(
            LeaveRequestRequest::class,
            [
                'partner_id'    => $employee->id,
                'leave_type_id' => 999999,
                'from_date'     => "{$year}-07-05",
                'to_date'       => "{$year}-07-10",
            ],
            'leave_type_id',
            'Leave against a leave type that does not exist'
        );

        $this->gate->expectRejected(
            LeaveTypeRequest::class,
            [
                'name_en'                 => 'Negative Entitlement',
                'is_paid'                 => true,
                'annual_entitlement_days' => -5,
            ],
            'annual_entitlement_days',
            'A leave type with a negative entitlement'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Leave — business rules
    // ══════════════════════════════════════════════════════════════

    private function leaveBusinessRules(CycleContext $ctx, int $year): void
    {
        $employee = $ctx->employees[0];
        $service  = app(LeaveRequestService::class);

        // Book a live request, then try to double-book the same days.
        $held = $service->create($ctx->companyId, [
            'partner_id'    => $employee->id,
            'leave_type_id' => $ctx->leaveTypes['annual']->id,
            'from_date'     => "{$year}-11-01",
            'to_date'       => "{$year}-11-05",
        ], $ctx->actor);
        $service->submit($held, $ctx->actor);

        $this->gate->expectRefused(
            fn () => $service->create($ctx->companyId, [
                'partner_id'    => $employee->id,
                'leave_type_id' => $ctx->leaveTypes['annual']->id,
                'from_date'     => "{$year}-11-04",
                'to_date'       => "{$year}-11-08",
            ], $ctx->actor),
            '/already has leave booked/i',
            'Two overlapping leave requests for one employee',
            'nobody can be on leave twice on the same day'
        );

        // Approval is not reachable from draft.
        $draft = $service->create($ctx->companyId, [
            'partner_id'    => $ctx->employees[1]->id,
            'leave_type_id' => $ctx->leaveTypes['annual']->id,
            'from_date'     => "{$year}-11-20",
            'to_date'       => "{$year}-11-21",
        ], $ctx->actor);

        $this->gate->expectRefused(
            fn () => $service->approve($draft, $ctx->actor),
            '/cannot be approved/i',
            'Approve a leave request that was never submitted',
            'the workflow must be followed in order'
        );

        // Leave outside every fiscal year has no entitlement window.
        $this->gate->expectRefused(
            fn () => $service->create($ctx->companyId, [
                'partner_id'    => $ctx->employees[1]->id,
                'leave_type_id' => $ctx->leaveTypes['annual']->id,
                'from_date'     => '1999-01-01',
                'to_date'       => '1999-01-03',
            ], $ctx->actor),
            '/no fiscal year covers/i',
            'Leave dated outside every fiscal year',
            'entitlement is tracked per fiscal year, so one must exist'
        );

        // Clean up so the main cycle is not blocked by the held dates.
        $service->cancel($held->fresh(), $ctx->actor);
    }

    // ══════════════════════════════════════════════════════════════
    //  Expense claims
    // ══════════════════════════════════════════════════════════════

    private function expenseClaimRules(CycleContext $ctx): void
    {
        $verify   = new Verify($this->log, $ctx->companyId);
        $employee = $ctx->employees[0];
        $service  = app(ExpenseClaimService::class);
        $account  = $verify->accountId('543');

        $this->gate->expectRejected(
            ExpenseClaimRequest::class,
            [
                'partner_id'    => $employee->id,
                'claim_date'    => '2026-01-01',
                'currency_id'   => $ctx->currencyId,
                'exchange_rate' => 1,
                'lines'         => [],
            ],
            'lines',
            'An expense claim with no receipts'
        );

        $this->gate->expectRejected(
            ExpenseClaimRequest::class,
            [
                'partner_id'    => $employee->id,
                'claim_date'    => '2026-01-01',
                'currency_id'   => $ctx->currencyId,
                'exchange_rate' => 1,
                'lines'         => [[
                    'expense_account_id' => $account,
                    'expense_date'       => '2026-01-01',
                    'description'        => 'Nothing',
                    'amount'             => -50,
                ]],
            ],
            'lines.0.amount',
            'An expense claim line for a negative amount'
        );

        // A claim raised for a customer rather than an employee.
        $this->gate->expectRefused(
            fn () => $service->create($ctx->companyId, [
                'partner_id'    => $ctx->customers[0]->id,
                'claim_date'    => '2026-01-01',
                'currency_id'   => $ctx->currencyId,
                'exchange_rate' => 1,
                'lines'         => [[
                    'expense_account_id' => $account,
                    'expense_date'       => '2026-01-01',
                    'description'        => 'Taxi',
                    'amount'             => 100,
                ]],
            ], $ctx->actor),
            '/not registered as an employee/i',
            'An expense claim for someone who is not an employee',
            'only employees can claim expenses back'
        );

        // Paying a claim nobody approved.
        $unapproved = $service->create($ctx->companyId, [
            'partner_id'    => $employee->id,
            'claim_date'    => '2026-01-01',
            'currency_id'   => $ctx->currencyId,
            'exchange_rate' => 1,
            'lines'         => [[
                'expense_account_id' => $account,
                'expense_date'       => '2026-01-01',
                'description'        => 'Taxi',
                'amount'             => 100,
            ]],
        ], $ctx->actor);

        $this->gate->expectRefused(
            fn () => $service->markPaid($unapproved, [
                'payment_source_type'     => ExpenseClaim::SOURCE_BANK_ACCOUNT,
                'payment_bank_account_id' => $ctx->bankAccounts[0]->id,
            ], $ctx->actor),
            '/cannot be paid/i',
            'Pay an expense claim that was never approved',
            'money must not leave against an unapproved claim'
        );

        $service->delete($unapproved->fresh(), $ctx->actor);
    }

    // ══════════════════════════════════════════════════════════════
    //  Tenancy
    // ══════════════════════════════════════════════════════════════

    private function tenantRules(CycleContext $ctx): void
    {
        $product = $ctx->sellableProducts()[0] ?? null;

        if (! $product) {
            return;
        }

        // A location id belonging to no company at all — the shape a
        // tampered request takes.
        $this->gate->expectRefused(
            fn () => app(StockLedgerService::class)->receive(
                companyId: $ctx->companyId,
                product: $product,
                inventoryLocationId: 999999,
                quantity: '1',
                unitCost: '10.00',
                actor: $ctx->actor,
            ),
            '/does not belong to this company/i',
            'Receive stock into a location this company does not own',
            'the stock ledger must verify location ownership itself'
        );
    }
}
