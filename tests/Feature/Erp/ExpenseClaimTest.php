<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaimTest
//
//  The Expense Claim lifecycle, and every journal entry it produces.
//
//  The accounting shape under test:
//
//    approve()    DR each line's expense account   line amount
//                 CR 232 Other Creditors            claim total
//
//    markPaid()   DR 232 Other Creditors            claim total
//                 CR bank account / cash safe       claim total
//
//  Two properties matter more than any other and are asserted
//  repeatedly below: nothing reaches the ledger before approval, and
//  232 clears to exactly zero once the claim is paid. A residue in
//  232 would mean the company still believes it owes an employee it
//  has already reimbursed.
//
//  Every expected figure is calculated by hand in the test from the
//  line amounts and the exchange rate.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ExpenseClaim;
use App\Models\Partner;
use App\Services\Accounting\ExpenseClaimService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class ExpenseClaimTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private ExpenseClaimService $service;
    private Partner $employee;
    private int $travelAccount;
    private int $adminAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = (new ErpScenario($this->actor))->createCompany();
        $this->service  = app(ExpenseClaimService::class);
        $this->employee = $this->scenario->createEmployee('Hala Mostafa');

        // 543 Employee Reimbursements — G&A and 533 Travel are both
        // seeded postable expense accounts. Migration 000234 records
        // that the account is picked per claim, never derived.
        $this->travelAccount = $this->scenario->coaId('543');
        $this->adminAccount  = $this->scenario->coaId('551') ?: $this->scenario->coaId('543');
    }

    private function draft(array $lines = null, float $rate = 1.0): ExpenseClaim
    {
        return $this->service->create($this->scenario->companyId, [
            'partner_id'    => $this->employee->id,
            'claim_date'    => '2026-03-31',
            'title'         => 'March field visits',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => $rate,
            'lines'         => $lines ?? [[
                'expense_account_id' => $this->travelAccount,
                'expense_date'       => '2026-03-12',
                'description'        => 'Taxi to client site',
                'amount'             => 450.00,
            ]],
        ], $this->actor);
    }

    private function approvedClaim(array $lines = null, float $rate = 1.0): ExpenseClaim
    {
        $claim = $this->service->submit($this->draft($lines, $rate), $this->actor);

        return $this->service->approve($claim, $this->actor, '2026-03-31');
    }

    private function liabilityBalance(): string
    {
        return $this->glBalance($this->scenario->companyId, ExpenseClaim::LIABILITY_ACCOUNT_CODE);
    }

    // ── Creation and totals ───────────────────────────────────────

    public function test_a_new_claim_is_a_draft_with_a_code_and_totals(): void
    {
        $claim = $this->draft();

        $this->assertSame(ExpenseClaim::STATUS_DRAFT, $claim->status);
        $this->assertStringStartsWith('EC/', $claim->claim_code);
        $this->assertMoneyEquals('450.00', (string) $claim->total_amount);
        $this->assertMoneyEquals('450.00', (string) $claim->amount_functional);
    }

    /**
     * Independent calculation: 450.00 + 1,275.50 + 89.25 = 1,814.75.
     */
    public function test_the_claim_total_is_the_sum_of_its_lines(): void
    {
        $claim = $this->draft([
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-12', 'description' => 'Taxi',   'amount' => 450.00],
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-13', 'description' => 'Hotel',  'amount' => 1275.50],
            ['expense_account_id' => $this->adminAccount,  'expense_date' => '2026-03-14', 'description' => 'Prints', 'amount' => 89.25],
        ]);

        $this->assertCount(3, $claim->lines);
        $this->assertMoneyEquals('1814.75', (string) $claim->total_amount);
    }

    public function test_editing_a_draft_replaces_its_lines_and_retotals(): void
    {
        $claim = $this->draft();

        $updated = $this->service->update($claim, [
            'partner_id'    => $this->employee->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [
                ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-12', 'description' => 'Taxi', 'amount' => 500.00],
                ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-15', 'description' => 'Fuel', 'amount' => 300.00],
            ],
        ], $this->actor);

        $this->assertCount(2, $updated->lines);
        $this->assertMoneyEquals('800.00', (string) $updated->total_amount);
    }

    // ── Nothing posts before approval ─────────────────────────────

    public function test_a_draft_claim_posts_nothing_to_the_ledger(): void
    {
        $before = DB::table('journal_entries')->where('company_id', $this->scenario->companyId)->count();

        $this->draft();

        $this->assertSame($before, DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)->count());
        $this->assertMoneyEquals('0.00', $this->liabilityBalance());
    }

    public function test_submitting_a_claim_still_posts_nothing(): void
    {
        $this->service->submit($this->draft(), $this->actor);

        $this->assertMoneyEquals(
            '0.00',
            $this->liabilityBalance(),
            'A submitted claim is a request, not yet an obligation.'
        );
    }

    public function test_a_rejected_claim_never_reaches_the_ledger(): void
    {
        $claim = $this->service->submit($this->draft(), $this->actor);

        $rejected = $this->service->reject($claim, $this->actor, 'Missing receipt.');

        $this->assertSame(ExpenseClaim::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Missing receipt.', $rejected->rejection_reason);
        $this->assertNull($rejected->journal_entry_id);
        $this->assertMoneyEquals('0.00', $this->liabilityBalance());
    }

    // ── Approval accounting ───────────────────────────────────────

    /**
     * Independent expectation for a single 450.00 line:
     *   DR 543 Employee Reimbursements — G&A   450.00
     *   CR 232 Other Creditors                 450.00
     */
    public function test_approval_debits_the_expense_and_credits_the_employee_liability(): void
    {
        $claim = $this->approvedClaim();

        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $claim->status);
        $this->assertNotNull($claim->journal_entry_id);

        $this->assertMoneyEquals(
            '450.00',
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount),
            'The expense account must carry the claim.'
        );

        // 232 is a liability: a credit balance is negative when signed
        // debit-minus-credit.
        $this->assertMoneyEquals(
            '-450.00',
            $this->liabilityBalance(),
            'The company now owes the employee 450.00.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After approving an expense claim.');
    }

    /** Each line reaches its OWN account — not one lumped total. */
    public function test_each_line_posts_to_its_own_expense_account(): void
    {
        if ($this->adminAccount === $this->travelAccount) {
            $this->markTestSkipped('This chart of accounts has only one usable expense account for the test.');
        }

        $this->approvedClaim([
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-12', 'description' => 'Taxi',   'amount' => 450.00],
            ['expense_account_id' => $this->adminAccount,  'expense_date' => '2026-03-14', 'description' => 'Prints', 'amount' => 89.25],
        ]);

        $this->assertMoneyEquals('450.00',
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount));
        $this->assertMoneyEquals('89.25',
            $this->glBalanceById($this->scenario->companyId, $this->adminAccount));
        $this->assertMoneyEquals('-539.25', $this->liabilityBalance());
    }

    /**
     * The rounding rule that keeps the entry balanced: the credit is
     * built from the same per-line converted figures as the debits.
     *
     * Three lines of 10.01 at a rate of 3.333333:
     *   per line   10.01 * 3.333333 = 33.3666... -> 33.36 (bcmath truncates)
     *   credit     33.36 * 3         = 100.08
     *   converting the total once instead: 30.03 * 3.333333 = 100.0999 -> 100.09
     * The two differ by a cent, and only the per-line route balances.
     */
    public function test_the_entry_balances_when_the_exchange_rate_creates_rounding(): void
    {
        $claim = $this->approvedClaim([
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-01', 'description' => 'A', 'amount' => 10.01],
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-02', 'description' => 'B', 'amount' => 10.01],
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-03', 'description' => 'C', 'amount' => 10.01],
        ], rate: 3.333333);

        $perLine     = bcmul('10.01', '3.333333', 2);          // 33.36
        $expected    = bcmul($perLine, '3', 2);                 // 100.08
        $convertOnce = bcmul(bcmul('10.01', '3', 2), '3.333333', 2); // 100.09

        // Confirm the scenario really does exercise the difference.
        $this->assertNotSame(0, bccomp($expected, $convertOnce, 2),
            'This test only means something if the two conversion routes disagree.');

        $this->assertMoneyEquals($expected, (string) $claim->amount_functional);
        $this->assertMoneyEquals($expected,
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount));
        $this->assertMoneyEquals(bcmul($expected, '-1', 2), $this->liabilityBalance());

        $this->assertLedgerBalanced($this->scenario->companyId, 'With a rounding-heavy exchange rate.');
    }

    public function test_a_claim_cannot_be_approved_twice(): void
    {
        $claim = $this->approvedClaim();

        $liabilityAfterFirst = $this->liabilityBalance();

        try {
            $this->service->approve($claim, $this->actor);
            $this->fail('Approving an already-approved claim should be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsStringIgnoringCase('cannot be approved', $e->getMessage());
        }

        $this->assertMoneyEquals(
            $liabilityAfterFirst,
            $this->liabilityBalance(),
            'A refused second approval must not post a second accrual.'
        );
    }

    public function test_a_draft_cannot_be_approved_without_submission(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be approved from status .draft./i');

        $this->service->approve($this->draft(), $this->actor);
    }

    public function test_an_empty_claim_cannot_be_submitted(): void
    {
        $claim = $this->service->create($this->scenario->companyId, [
            'partner_id'    => $this->employee->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [],
        ], $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no lines/i');

        $this->service->submit($claim, $this->actor);
    }

    // ── Payment accounting ────────────────────────────────────────

    /**
     * The property that matters most: once paid, 232 must be exactly
     * zero. A residue means the company still thinks it owes money it
     * has already handed over.
     */
    public function test_paying_a_claim_from_a_cash_safe_clears_the_liability(): void
    {
        $safeId = $this->scenario->createCashSafe();
        $claim  = $this->approvedClaim();

        $paid = $this->service->markPaid($claim, [
            'payment_date'            => '2026-04-05',
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
            'payment_reference'       => 'Cash voucher 88',
        ], $this->actor);

        $this->assertSame(ExpenseClaim::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->payment_journal_entry_id);

        $this->assertMoneyEquals(
            '0.00',
            $this->liabilityBalance(),
            'Other Creditors must clear to exactly zero once the claim is paid.'
        );

        $this->assertMoneyEquals(
            '-450.00',
            $this->glBalanceById($this->scenario->companyId, $safeId),
            'The cash safe must fall by the amount paid.'
        );

        // The expense stays — paying does not un-incur a cost.
        $this->assertMoneyEquals('450.00',
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount));

        $this->assertLedgerBalanced($this->scenario->companyId, 'After paying an expense claim.');
    }

    public function test_paying_a_claim_from_a_bank_account_credits_that_bank(): void
    {
        $bankAccount = $this->scenario->createBankAccount('Test Bank');
        $claim       = $this->approvedClaim();

        $this->service->markPaid($claim, [
            'payment_date'            => '2026-04-05',
            'payment_source_type'     => ExpenseClaim::SOURCE_BANK_ACCOUNT,
            'payment_bank_account_id' => $bankAccount->id,
        ], $this->actor);

        $this->assertMoneyEquals('0.00', $this->liabilityBalance());
        $this->assertMoneyEquals(
            '-450.00',
            $this->glBalanceById($this->scenario->companyId, (int) $bankAccount->coa_account_id),
            'The bank account must fall by the amount paid.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    public function test_an_unapproved_claim_cannot_be_paid(): void
    {
        $safeId = $this->scenario->createCashSafe();
        $claim  = $this->service->submit($this->draft(), $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be paid from status .submitted./i');

        $this->service->markPaid($claim, [
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
        ], $this->actor);
    }

    public function test_a_claim_cannot_be_paid_twice(): void
    {
        $safeId = $this->scenario->createCashSafe();
        $claim  = $this->approvedClaim();

        $payment = [
            'payment_date'            => '2026-04-05',
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
        ];

        $paid = $this->service->markPaid($claim, $payment, $this->actor);

        try {
            $this->service->markPaid($paid, $payment, $this->actor);
            $this->fail('Paying an already-paid claim should be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsStringIgnoringCase('cannot be paid', $e->getMessage());
        }

        $this->assertMoneyEquals(
            '-450.00',
            $this->glBalanceById($this->scenario->companyId, $safeId),
            'A refused second payment must not take the money twice.'
        );
        $this->assertMoneyEquals('0.00', $this->liabilityBalance());
    }

    // ── Reopening ─────────────────────────────────────────────────

    /**
     * Reopening an approved claim reverses the accrual and the
     * ledger returns to zero.
     */
    public function test_reopening_an_approved_claim_reverses_the_accrual(): void
    {
        $claim = $this->approvedClaim();

        $reopened = $this->service->setToDraft($claim, $this->actor);

        $this->assertSame(ExpenseClaim::STATUS_DRAFT, $reopened->status);
        $this->assertNull($reopened->journal_entry_id);

        $this->assertMoneyEquals(
            '0.00',
            $this->liabilityBalance(),
            'The true ledger nets to zero once the accrual is reversed.'
        );
        $this->assertMoneyEquals(
            '0.00',
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount)
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After reopening a claim.');
    }

    /**
     * REGRESSION GUARD — audit finding ACC-002, inherited and now
     * inherited FIXED.
     *
     * Reopening goes through JournalEntryService::reverse(), so this
     * module used to show a negative travel expense on every report
     * after a claim was reopened — a defect in shared reporting
     * code, not in this module. It is pinned here rather than only
     * in ReversalReportingTest so the shared fix is proven to reach
     * this module too, and cannot regress for it alone.
     */
    public function test_reopening_no_longer_leaves_a_negative_expense_on_reports(): void
    {
        $claim = $this->approvedClaim();
        $this->service->setToDraft($claim, $this->actor);

        $true   = $this->glBalanceById($this->scenario->companyId, $this->travelAccount);
        $preFix = $this->preAcc002FilteredBalanceById($this->scenario->companyId, $this->travelAccount);

        $this->assertMoneyEquals('0.00', $true, 'The ledger itself is correct.');

        $reported = app(\App\Services\Accounting\AccountBalanceCalculator::class)
            ->balancesAsOf(
                $this->scenario->companyId,
                now()->addYear()->toDateString(),
                \App\Models\ChartOfAccount::withoutGlobalScopes()
                    ->where('id', $this->travelAccount)->get()->keyBy('id')
            )[$this->travelAccount];

        $this->assertMoneyEquals(
            '0.00',
            $reported,
            'ACC-002 fix: the reversed accrual is counted alongside its reversal, '
            .'so the travel expense account reports zero, not a negative balance.'
        );

        $this->assertMoneyEquals(
            '-450.00',
            $preFix,
            'Guard the guard: the old status filter still produces -450.00 on this '
            .'same data, so the assertion above is genuinely discriminating.'
        );
    }

    public function test_a_paid_claim_cannot_be_reopened(): void
    {
        $safeId = $this->scenario->createCashSafe();
        $paid   = $this->service->markPaid($this->approvedClaim(), [
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
        ], $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already been paid/i');

        $this->service->setToDraft($paid, $this->actor);
    }

    public function test_an_approved_claim_cannot_be_edited(): void
    {
        $claim = $this->approvedClaim();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/can no longer be edited/i');

        $this->service->update($claim, [
            'partner_id'    => $this->employee->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [],
        ], $this->actor);
    }

    // ── Guards ────────────────────────────────────────────────────

    public function test_a_claim_cannot_be_raised_for_a_non_employee(): void
    {
        $this->scenario->createPartners();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/not registered as an employee/i');

        $this->service->create($this->scenario->companyId, [
            'partner_id'    => $this->scenario->customers[0]->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [[
                'expense_account_id' => $this->travelAccount,
                'expense_date'       => '2026-03-12',
                'description'        => 'Taxi',
                'amount'             => 100,
            ]],
        ], $this->actor);
    }

    public function test_a_claim_cannot_use_another_companys_expense_account(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignAccount = $other->createCashSafe('Their Safe');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/does not belong to this company/i');

        $this->draft([[
            'expense_account_id' => $foreignAccount,
            'expense_date'       => '2026-03-12',
            'description'        => 'Taxi',
            'amount'             => 100,
        ]]);
    }

    public function test_a_claim_cannot_be_paid_from_another_companys_bank(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignBank = $other->createBankAccount('Their Bank');

        $claim = $this->approvedClaim();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/does not belong to this company/i');

        $this->service->markPaid($claim, [
            'payment_source_type'     => ExpenseClaim::SOURCE_BANK_ACCOUNT,
            'payment_bank_account_id' => $foreignBank->id,
        ], $this->actor);
    }

    /** A failed payment must leave no partial posting behind. */
    public function test_a_refused_payment_leaves_no_partial_entry(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $foreignBank = $other->createBankAccount('Their Bank');

        $claim = $this->approvedClaim();

        $entriesBefore = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)->count();

        try {
            $this->service->markPaid($claim, [
                'payment_source_type'     => ExpenseClaim::SOURCE_BANK_ACCOUNT,
                'payment_bank_account_id' => $foreignBank->id,
            ], $this->actor);
            $this->fail('Cross-company payment should have been refused.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame($entriesBefore, DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)->count());

        $this->assertMoneyEquals(
            '-450.00',
            $this->liabilityBalance(),
            'The liability must still stand — nothing was paid.'
        );
    }

    // ── Whole-lifecycle cross-check ───────────────────────────────

    /**
     * One claim from creation to payment, checked at every stage
     * against figures calculated by hand.
     *
     *   lines      450.00 + 1,275.50 + 89.25 = 1,814.75
     *   approved   DR expenses 1,814.75 / CR 232 1,814.75
     *   paid       DR 232 1,814.75 / CR safe 1,814.75
     *   net effect expenses +1,814.75, cash -1,814.75, 232 zero
     */
    public function test_the_whole_claim_lifecycle_nets_correctly(): void
    {
        $safeId = $this->scenario->createCashSafe();

        $claim = $this->approvedClaim([
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-12', 'description' => 'Taxi',   'amount' => 450.00],
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-13', 'description' => 'Hotel',  'amount' => 1275.50],
            ['expense_account_id' => $this->travelAccount, 'expense_date' => '2026-03-14', 'description' => 'Prints', 'amount' => 89.25],
        ]);

        $expectedTotal = bcadd(bcadd('450.00', '1275.50', 2), '89.25', 2); // 1814.75
        $this->assertMoneyEquals('1814.75', $expectedTotal);
        $this->assertMoneyEquals($expectedTotal, (string) $claim->amount_functional);
        $this->assertMoneyEquals(bcmul($expectedTotal, '-1', 2), $this->liabilityBalance());

        $this->service->markPaid($claim, [
            'payment_date'            => '2026-04-05',
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
        ], $this->actor);

        $this->assertMoneyEquals($expectedTotal,
            $this->glBalanceById($this->scenario->companyId, $this->travelAccount),
            'The full cost stays in the expense account.');
        $this->assertMoneyEquals(bcmul($expectedTotal, '-1', 2),
            $this->glBalanceById($this->scenario->companyId, $safeId),
            'Cash falls by the full amount.');
        $this->assertMoneyEquals('0.00', $this->liabilityBalance(),
            'And the company owes nothing further.');

        $this->assertLedgerBalanced($this->scenario->companyId, 'Full expense claim lifecycle.');
    }

    /** Two claims for two employees must not contaminate each other. */
    public function test_claims_for_different_employees_stay_separate(): void
    {
        $other = $this->scenario->createEmployee('Youssef Nabil');

        $this->approvedClaim();

        $secondClaim = $this->service->create($this->scenario->companyId, [
            'partner_id'    => $other->id,
            'claim_date'    => '2026-03-31',
            'currency_id'   => $this->scenario->currencyId,
            'exchange_rate' => 1,
            'lines'         => [[
                'expense_account_id' => $this->travelAccount,
                'expense_date'       => '2026-03-20',
                'description'        => 'Bus fare',
                'amount'             => 120.00,
            ]],
        ], $this->actor);

        $this->service->approve($this->service->submit($secondClaim, $this->actor), $this->actor, '2026-03-31');

        $this->assertMoneyEquals(
            '-570.00',
            $this->liabilityBalance(),
            'Other Creditors carries both claims: 450.00 + 120.00.'
        );
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * Paying a FOREIGN-CURRENCY claim must settle what was accrued,
     * not the claim-currency figure. The two are only equal when the
     * exchange rate is 1, so a test at rate 1 cannot tell them apart
     * — this one runs at 3.5 deliberately.
     *
     * Independent calculation:
     *   line          200.00 in claim currency
     *   rate          3.5
     *   accrued       200.00 * 3.5 = 700.00 to Other Creditors
     *   paying 200.00 instead of 700.00 would leave 500.00 stranded
     *   in 232 for a claim that has been fully settled.
     *
     * (Found by mutation testing: swapping amount_functional for
     * total_amount in markPaid() survived the suite until this case
     * existed.)
     */
    public function test_paying_a_foreign_currency_claim_settles_the_accrued_amount(): void
    {
        $safeId = $this->scenario->createCashSafe();

        $claim = $this->approvedClaim([[
            'expense_account_id' => $this->travelAccount,
            'expense_date'       => '2026-03-12',
            'description'        => 'Overseas taxi',
            'amount'             => 200.00,
        ]], rate: 3.5);

        $accrued = bcmul('200.00', '3.5', 2); // 700.00
        $this->assertMoneyEquals('700.00', $accrued);
        $this->assertMoneyEquals('200.00', (string) $claim->total_amount, 'Claim currency.');
        $this->assertMoneyEquals($accrued, (string) $claim->amount_functional, 'Functional currency.');
        $this->assertMoneyEquals(bcmul($accrued, '-1', 2), $this->liabilityBalance());

        $this->service->markPaid($claim, [
            'payment_date'            => '2026-04-05',
            'payment_source_type'     => ExpenseClaim::SOURCE_CASH_SAFE,
            'payment_safe_account_id' => $safeId,
        ], $this->actor);

        $this->assertMoneyEquals(
            '0.00',
            $this->liabilityBalance(),
            'Other Creditors must clear completely — settling the claim-currency '
            .'figure instead would strand 500.00 against a fully paid claim.'
        );

        $this->assertMoneyEquals(
            bcmul($accrued, '-1', 2),
            $this->glBalanceById($this->scenario->companyId, $safeId),
            'Cash must fall by the functional amount actually owed.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'Foreign-currency claim, paid.');
    }
}
