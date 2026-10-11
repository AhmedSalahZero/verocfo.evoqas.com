<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  MediumTermLoanAccountingTest
//
//  REGRESSION GUARD — audit finding TRS-002, fixed 2026-09.
//
//  A Medium-Term Loan recorded its facility, its repayment schedule
//  and every settlement correctly, and posted NOTHING to the general
//  ledger at either end.
//
//  The cycle is CashVero's, read from that codebase directly rather
//  than assumed:
//
//    * the bank does not hand an MTL over as cash — it pays the
//      company's suppliers directly out of it, so the loan is a
//      PAYMENT SOURCE, drawn progressively, exactly like a Clean
//      Overdraft. Nothing is posted when the loan is created.
//    * repaying an instalment pays interest FIRST; only what is left
//      after the interest reduces the principal
//      (LoanScheduleSettlement::principlePaidFor, owner-confirmed
//      2026-08-16).
//    * only the principal reduces the drawn balance and replenishes
//      the room available to draw again. Interest is a cost of
//      carrying the debt, never a repayment of it.
//
//  In Vero this rides the machinery that already exists: the facility
//  carries 211 Long-Term Loans as its own COA account, so a payment
//  made from it credits the loan liability through the ordinary
//  payment path, and FacilitySettlementEngine tracks
//  outstanding_balance against facility_limit — which is CashVero's
//  room by another name.
//
//      drawdown     Dr supplier / expense
//                   Cr 211 Long-Term Loans        (via the payment)
//
//      settlement   Dr 211 Long-Term Loans        (principal half)
//                   Dr 551 Interest on Loans      (interest half)
//                   Cr the paying bank account
//
//  Every figure is calculated by hand, never read back from what the
//  application produced.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\MtlMovement;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Models\JournalEntry;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\FinancialInstitutions\MtlService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class MediumTermLoanAccountingTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private $bank;

    /** 211 Long-Term Loans · 551 Interest on Loans */
    private const LOAN = '211';
    private const INTEREST = '551';

    protected function setUp(): void
    {
        parent::setUp();
        // bootstrap(), not createCompany(): drawing on the loan means
        // paying a real supplier bill from it, which needs a product
        // and a supplier to exist.
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
        $this->bank = $this->scenario->createBankAccount('Loan Bank');
    }

    // ── Arrange ───────────────────────────────────────────────────

    private function drawLoan(string $limit = '1200000.00', string $entryType = 'new'): CreditFacility
    {
        return app(MtlService::class)->create($this->scenario->companyId, [
            'bank_id'              => $this->bank->bank_id,
            'name'                 => 'Expansion Loan',
            'entry_type'           => $entryType,
            'start_date'           => '2026-01-15',
            'review_date'          => '2028-01-15',
            'currency_id'          => $this->scenario->currencyId,
            'facility_limit'       => $limit,
            'account_number'       => 'MTL-TEST-'.random_int(1000, 9999),
            'borrowing_rate'       => 18,
            'bank_margin_rate'     => 2,
            'tenure_months'        => 24,
            'installment_interval' => 'monthly',
            'bank_account_id'      => $this->bank->id,
        ], $this->actor);
    }

    /**
     * One instalment, written by hand so the test owns the numbers:
     * 50,000.00 due, of which 40,000.00 principal and 10,000.00
     * interest.
     */
    private function scheduleLine(
        CreditFacility $facility,
        string $payment = '50000.00',
        string $principal = '40000.00',
        string $interest = '10000.00',
        string $due = '2026-02-15'
    ): MtlScheduleLine {
        app(MtlService::class)->saveSchedule($facility, [[
            'due_date'          => $due,
            'beginning_balance' => '1200000.00',
            'schedule_payment'  => $payment,
            'interest_amount'   => $interest,
            'principal_amount'  => $principal,
            'end_balance'       => bcsub('1200000.00', $principal, 2),
        ]], $this->actor);

        return MtlScheduleLine::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)->orderBy('sort_order')->firstOrFail();
    }

    private function settle(MtlScheduleLine $line, string $amount, string $date = '2026-02-15')
    {
        return app(MtlService::class)->recordSettlement($line->fresh(), [
            'settlement_date'    => $date,
            'settlement_amount'  => $amount,
            'current_account_id' => $this->bank->id,
        ], $this->actor);
    }

    /**
     * Draws money out of the loan the way a user does: by paying a
     * supplier from it. This is the whole point of the facility —
     * the bank pays the company's suppliers directly out of the loan.
     *
     *   Dr Accounts Payable
     *   Cr 211 Long-Term Loans     (the facility's own COA account)
     *
     * and the facility's outstanding balance grows by the same amount.
     */
    private function drawFromLoan(CreditFacility $facility, string $amount, string $date = '2026-01-20'): void
    {
        $product = $this->scenario->products[0];

        $grn = $this->scenario->receiveStock([[$product, 100, (float) bcdiv($amount, '100', 2)]], '2026-01-10');
        $bill = $this->scenario->billGrn($grn, '2026-01-12', vatRate: 0);

        app(SupplierPaymentService::class)->record($this->scenario->companyId, [
            'partner_id'                => $bill->partner_id,
            'payment_type'              => 'settlement',
            'payment_date'              => $date,
            'money_type'                => 'outgoing_transfer',
            'pay_currency_id'           => $this->scenario->currencyId,
            'amount'                    => $amount,
            'source_type'               => 'credit_facility',
            'source_credit_facility_id' => $facility->id,
            'settlements'               => [[
                'supplier_bill_id'  => $bill->id,
                'settlement_amount' => $amount,
            ]],
        ], $this->actor);
    }

    private function outstanding(CreditFacility $facility): string
    {
        return (string) $facility->fresh()->outstanding_balance;
    }

    /** limit − outstanding — CashVero's `room`, by another name. */
    private function room(CreditFacility $facility): string
    {
        return bcsub((string) $facility->facility_limit, $this->outstanding($facility), 2);
    }

    private function bal(string $code): string
    {
        return $this->glBalance($this->scenario->companyId, $code);
    }

    private function bankBal(): string
    {
        return $this->glBalanceById($this->scenario->companyId, (int) $this->bank->coa_account_id);
    }

    // ══════════════════════════════════════════════════════════════
    //  Drawdown
    // ══════════════════════════════════════════════════════════════

    /**
     * Creating a loan posts NOTHING. The money has not moved: the
     * bank pays suppliers out of it later, and each of those payments
     * posts its own entry.
     *
     * An earlier version posted the full 1,200,000.00 as cash into a
     * bank account the moment the loan was created — showing cash the
     * company had not received and a liability it had not incurred.
     */
    public function test_creating_a_loan_posts_nothing_and_draws_nothing(): void
    {
        $facility = $this->drawLoan();

        $this->assertMoneyEquals('0.00', $this->bankBal(), 'No cash has arrived.');
        $this->assertMoneyEquals('0.00', $this->bal(self::LOAN), 'And no liability has been incurred yet.');
        $this->assertMoneyEquals('0.00', $this->outstanding($facility), 'Nothing is drawn.');
        $this->assertMoneyEquals('1200000.00', $this->room($facility), 'The whole limit is available.');
    }

    /**
     * A NEW loan carries 211 Long-Term Loans as its own COA account,
     * which is what makes a payment from it credit the loan liability
     * through the ordinary payment path, with no special code.
     */
    public function test_a_new_loan_is_linked_to_the_loan_liability_account(): void
    {
        $facility = $this->drawLoan();

        $this->assertSame(
            $this->scenario->coaId(self::LOAN),
            (int) $facility->coa_account_id,
            'Without this link the facility cannot be paid from at all.'
        );
    }

    /** An EXISTING loan was drawn before this system — it is not a source. */
    public function test_an_existing_loan_is_not_a_payment_source(): void
    {
        $facility = $this->drawLoan(entryType: 'existing');

        $this->assertNull($facility->coa_account_id, 'Nothing left to draw, so no COA link.');

        $payable = CreditFacility::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->payableSource()->pluck('id');

        $this->assertNotContains($facility->id, $payable->all(),
            'An already-consumed loan must not appear as a payment source.');
    }

    /** And a new one does appear. */
    public function test_a_new_loan_appears_as_a_payment_source(): void
    {
        $facility = $this->drawLoan();

        $payable = CreditFacility::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->payableSource()->pluck('id');

        $this->assertContains($facility->id, $payable->all());
    }

    // ══════════════════════════════════════════════════════════════
    //  Drawdown
    // ══════════════════════════════════════════════════════════════

    /**
     * Paying a supplier from the loan draws it down.
     *
     *   paid 300,000.00 to a supplier from the loan
     *       Cr 211 Long-Term Loans   300,000.00   ->  balance -300,000.00
     *       outstanding              300,000.00
     *       room  1,200,000 - 300,000 = 900,000.00
     */
    public function test_paying_a_supplier_from_the_loan_draws_it_down(): void
    {
        $facility = $this->drawLoan();

        $this->drawFromLoan($facility, '300000.00');

        $this->assertMoneyEquals('-300000.00', $this->bal(self::LOAN),
            'The loan liability grows by exactly what was paid out of it.');
        $this->assertMoneyEquals('300000.00', $this->outstanding($facility));
        $this->assertMoneyEquals('900000.00', $this->room($facility),
            'Room is the limit less what has been drawn.');

        $this->assertMoneyEquals('0.00', $this->bankBal(),
            'And no company bank account was touched — the bank paid the supplier direct.');

        $this->assertLedgerBalanced($this->scenario->companyId, 'After drawing on the loan.');
    }

    /** A drawdown cannot exceed the limit. */
    public function test_a_drawdown_beyond_the_limit_is_refused(): void
    {
        $facility = $this->drawLoan('100000.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/limit/i');

        $this->drawFromLoan($facility, '150000.00');
    }

    // ══════════════════════════════════════════════════════════════
    //  Settlement — interest first
    // ══════════════════════════════════════════════════════════════

    /**
     * A loan drawn 300,000.00, then an instalment paid in full.
     *
     *   instalment 50,000.00 = interest 10,000.00 + principal 40,000.00
     *
     *       Dr 211   40,000.00     liability  -300,000 -> -260,000
     *       Dr 551   10,000.00
     *       Cr bank  50,000.00
     *
     *       outstanding  300,000 - 40,000 = 260,000.00
     *       room       1,200,000 - 260,000 = 940,000.00
     *
     * Only the principal touches the facility. The interest is a cost
     * of carrying the debt, not a repayment of it.
     */
    public function test_a_full_settlement_splits_interest_and_principal(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);

        $bankBefore = $this->bankBal();

        $this->settle($line, '50000.00');

        $this->assertMoneyEquals('-260000.00', $this->bal(self::LOAN),
            '300,000 drawn less 40,000 of principal repaid.');
        $this->assertMoneyEquals('10000.00', $this->bal(self::INTEREST),
            'The interest half is an expense.');
        $this->assertMoneyEquals(bcsub($bankBefore, '50000.00', 2), $this->bankBal(),
            'The bank paid the whole 50,000.00 instalment.');

        $this->assertMoneyEquals('260000.00', $this->outstanding($facility),
            'Only the principal reduced what the loan has drawn.');
        $this->assertMoneyEquals('940000.00', $this->room($facility),
            'And only the principal replenished the room.');

        $this->assertLedgerBalanced($this->scenario->companyId, 'After a full instalment.');
    }

    /**
     * INTEREST FIRST — the project owner's own worked example, settled
     * in CashVero on 2026-08-16.
     *
     *   instalment 50,000.00 = interest 10,000.00 + principal 40,000.00
     *   paid 20,000.00:
     *       interest  = min(20,000, 10,000)          = 10,000.00
     *       principal = min(20,000 - 10,000, 40,000) = 10,000.00
     *
     * Pro-rata would have said 4,000 / 16,000. The two rules disagree
     * on every partial payment, so the numbers are named here rather
     * than derived.
     */
    public function test_a_partial_settlement_pays_interest_first(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);

        $this->settle($line, '20000.00');

        $this->assertMoneyEquals('10000.00', $this->bal(self::INTEREST),
            'The instalment\'s whole 10,000.00 of interest is covered first.');
        $this->assertMoneyEquals('-290000.00', $this->bal(self::LOAN),
            'Only the remaining 10,000.00 reduces the loan.');
        $this->assertMoneyEquals('290000.00', $this->outstanding($facility));
    }

    /**
     * The owner's other example: a payment SMALLER than the interest
     * repays no principal at all, so the drawn balance does not move.
     */
    public function test_a_payment_below_the_interest_repays_no_principal(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);

        $this->settle($line, '6000.00');

        $this->assertMoneyEquals('6000.00', $this->bal(self::INTEREST));
        $this->assertMoneyEquals('-300000.00', $this->bal(self::LOAN),
            'Nothing has reached principal yet. Pro-rata would wrongly have repaid 4,800.00.');
        $this->assertMoneyEquals('300000.00', $this->outstanding($facility),
            'And the room has not been replenished by a penny.');

        $this->assertSame(
            0,
            MtlMovement::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('movement_type', MtlMovement::TYPE_PRINCIPAL_REPAYMENT)->count(),
            'No principal movement row was written either.'
        );
    }

    /**
     * THE INVARIANT. However many partial payments an instalment is
     * settled in, they sum to exactly its stored principal and
     * interest. Interest-first changes how each payment divides,
     * never what they add up to.
     *
     *   50,000.00 paid as 20,000 + 15,000 + 15,000
     *       principal totals exactly 40,000.00
     *       interest  totals exactly 10,000.00
     */
    public function test_partial_settlements_sum_to_the_instalments_own_split(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);

        $this->settle($line, '20000.00', '2026-02-15');
        $this->settle($line, '15000.00', '2026-02-20');
        $this->settle($line, '15000.00', '2026-02-25');

        $this->assertMoneyEquals('-260000.00', $this->bal(self::LOAN),
            'Principal across three payments totals exactly 40,000.00.');
        $this->assertMoneyEquals('10000.00', $this->bal(self::INTEREST),
            'Interest across three payments totals exactly 10,000.00.');
        $this->assertMoneyEquals('260000.00', $this->outstanding($facility));
    }

    /**
     * Thirds of a non-divisible instalment.
     *
     *   instalment 10,000.00 = interest 3,333.33 + principal 6,666.67
     *   paid 3,333.34 / 3,333.33 / 3,333.33
     *
     * Each payment's share is the difference between consecutive
     * running totals, so the three add up to exactly the line's own
     * figures with nothing left adrift.
     */
    public function test_a_non_divisible_instalment_leaves_no_residue(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility, '10000.00', '6666.67', '3333.33');

        $this->settle($line, '3333.34', '2026-02-15');
        $this->settle($line, '3333.33', '2026-02-16');
        $this->settle($line, '3333.33', '2026-02-17');

        $this->assertMoneyEquals('-293333.33', $this->bal(self::LOAN),
            'Principal totals exactly 6,666.67 — the line\'s own figure, to the cent.');
        $this->assertMoneyEquals('3333.33', $this->bal(self::INTEREST),
            'Interest totals exactly 3,333.33 — no cent left adrift.');
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * An 'existing' loan has no drawdown in this system to pay down,
     * so a settlement posts the ledger entry and touches no facility
     * balance — CashVero's own hasDrawdowns() guard.
     */
    public function test_an_existing_loans_settlement_posts_the_ledger_but_no_facility_movement(): void
    {
        $facility = $this->drawLoan(entryType: 'existing');
        $line = $this->scheduleLine($facility);

        $this->settle($line, '50000.00');

        // 211 goes DEBIT-positive here, which looks odd until you see
        // why: this loan's liability was never credited in this
        // system — it arrived through Opening Balances, which this
        // scenario does not set up. The repayment debits 211 against
        // that opening credit. What matters is that the repayment
        // reaches the ledger at all, and at the right amount.
        $this->assertMoneyEquals('40000.00', $this->bal(self::LOAN),
            'The principal repayment still reaches the ledger.');
        $this->assertMoneyEquals('10000.00', $this->bal(self::INTEREST));
        $this->assertMoneyEquals('0.00', $this->outstanding($facility),
            'But there is no drawdown here to pay down.');
    }

    /** An interest-free instalment posts principal only — no empty line. */
    public function test_an_interest_free_instalment_posts_principal_only(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility, '40000.00', '40000.00', '0.00');

        $this->settle($line, '40000.00');

        $this->assertMoneyEquals('-260000.00', $this->bal(self::LOAN));
        $this->assertMoneyEquals('0.00', $this->bal(self::INTEREST),
            'No interest was due, so no interest entry exists.');

        $this->assertSame(
            0,
            MtlMovement::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('movement_type', MtlMovement::TYPE_INTEREST_EXPENSE)->count(),
            'And no zero-value movement row was written either.'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Reversal and traceability
    // ══════════════════════════════════════════════════════════════

    /** Deleting a settlement reverses its entry rather than erasing it. */
    public function test_deleting_a_settlement_reverses_its_journal_entry(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);

        $settlement = $this->settle($line, '50000.00');
        $this->assertMoneyEquals('-260000.00', $this->bal(self::LOAN), 'Precondition.');

        app(MtlService::class)->deleteSettlement($settlement, $this->actor);

        $this->assertMoneyEquals('-300000.00', $this->bal(self::LOAN),
            'The liability is back to what was drawn.');
        $this->assertMoneyEquals('0.00', $this->bal(self::INTEREST),
            'And the interest expense is undone.');

        $this->assertGreaterThan(
            0,
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('source_type', 'reversal')->count(),
            'Reversed through JournalEntryService::reverse(), so the correction is on '.
            'the record rather than deleted from it.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After reversing a settlement.');
    }

    /** Every movement is traceable to the entry that posted it. */
    public function test_movements_are_marked_posted_and_linked_to_their_entry(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);
        $this->settle($line, '50000.00');

        $movements = MtlMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)->get();

        $this->assertCount(2, $movements, 'One principal, one interest — no drawdown row: '.
            'a drawdown is an ordinary payment now, recorded as a facility withdrawal.');

        foreach ($movements as $m) {
            $this->assertSame(MtlMovement::STATUS_POSTED, $m->status, "{$m->movement_type} must be posted.");
            $this->assertNotNull($m->posted_journal_entry_id, "{$m->movement_type} must name its entry.");
            $this->assertNotNull($m->posted_at);
        }

        $this->assertEqualsCanonicalizing(
            [MtlMovement::TYPE_PRINCIPAL_REPAYMENT, MtlMovement::TYPE_INTEREST_EXPENSE],
            $movements->pluck('movement_type')->all()
        );
    }

    /** Both settlement legs belong to ONE journal entry — one payment. */
    public function test_both_settlement_legs_share_one_journal_entry(): void
    {
        $facility = $this->drawLoan();
        $this->drawFromLoan($facility, '300000.00');
        $line = $this->scheduleLine($facility);
        $this->settle($line, '50000.00');

        $entryIds = MtlMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->pluck('posted_journal_entry_id')->unique();

        $this->assertCount(1, $entryIds, 'Principal and interest are two legs of one payment.');
    }

    /** The accounts the fix depends on must exist and be postable. */
    public function test_the_loan_and_interest_accounts_are_seeded_and_postable(): void
    {
        foreach ([self::LOAN => 'Long-Term Loans', self::INTEREST => 'Interest on Loans'] as $code => $label) {
            $account = \App\Models\ChartOfAccount::findByCode($this->scenario->companyId, $code);

            $this->assertNotNull($account, "Account {$code} ({$label}) must be seeded.");
            $this->assertTrue((bool) $account->is_postable, "Account {$code} must be postable.");
        }
    }
}
