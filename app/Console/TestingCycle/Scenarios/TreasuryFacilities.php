<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  TreasuryFacilities
//
//  The bank products a real company actually uses and this system had
//  never been driven through: a medium-term loan with its repayment
//  schedule, the three secured overdraft variants, a time deposit
//  opened / paid interest / broken, reverse factoring, and a foreign
//  currency deal.
//
//  These are the largest untested surface in the ERP. They are also
//  the ones where "it saved a row" and "it accounted for the money"
//  are most often different things — several of them keep a perfectly
//  correct operational record and post nothing at all to the general
//  ledger. So every step here asks BOTH questions:
//
//      did the operation record what happened?
//      did the money reach the books?
//
//  A facility that answers yes to the first and no to the second is
//  reported, because that is a loan the balance sheet does not know
//  the company owes.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\JournalEntry;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Services\ChartOfAccountService;
use App\Services\FinancialInstitutions\BuySellCurrencyService;
use App\Services\FinancialInstitutions\CommercialPaperOverdraftService;
use App\Services\FinancialInstitutions\ContractAssignmentOdService;
use App\Services\FinancialInstitutions\FullySecuredOverdraftService;
use App\Services\FinancialInstitutions\MtlService;
use App\Services\FinancialInstitutions\ReverseFactoringTransactionService;
use App\Services\FinancialInstitutions\TimeDepositService;

class TreasuryFacilities
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(CycleContext $ctx, int $year): void
    {
        $this->log->enterPeriod("treasury facilities {$year}");
        $this->log->note('Opening the bank facilities a company actually uses.');

        $this->mediumTermLoan($ctx, $year);
        $timeDeposit = $this->timeDeposit($ctx, $year);
        $this->commercialPaperOverdraft($ctx, $year);
        $this->fullySecuredOverdraft($ctx, $year, $timeDeposit);
        $this->contractAssignmentOverdraft($ctx, $year);
        $this->reverseFactoring($ctx, $year);
        $this->buyForeignCurrency($ctx, $year);
    }

    // ══════════════════════════════════════════════════════════════
    //  Medium-term loan — borrow, schedule, repay
    // ══════════════════════════════════════════════════════════════

    /**
     * The company borrows 1,200,000 over 24 months and repays the
     * first two installments. Three things are checked, in order of
     * how much they cost if wrong:
     *
     *   1. does the money reach the general ledger at all?
     *   2. does the schedule's own arithmetic close (each line's
     *      end balance is the next line's beginning balance, and the
     *      last one lands on zero)?
     *   3. does a settlement reduce the remaining balance by exactly
     *      what was paid?
     */
    private function mediumTermLoan(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];
        $limit  = '1200000.00';

        $bankBefore = $verify->trueBalance((int) $bank->coa_account_id);

        $facility = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\MtlRequest::class,
            [
                'bank_id'              => $bank->bank_id,
                'name'                 => 'Expansion Loan '.$year,
                'entry_type'           => 'new',
                'start_date'           => $year.'-01-15',
                'review_date'          => ($year + 2).'-01-15',
                'currency_id'          => $ctx->currencyId,
                'facility_limit'       => $limit,
                'account_number'       => 'MTL-'.$ctx->companyId.'-'.$year,
                // TRS-002 (2026-09) — a new loan posts its drawdown, so
                // the bank account that received the cash is stated on
                // the form now, exactly as the Clean Overdraft does.
                'bank_account_id'      => $bank->id,
                'borrowing_rate'       => 18,
                'bank_margin_rate'     => 2,
                'tenure_months'        => 24,
                'installment_interval' => 'monthly',
            ],
            fn (array $data) => app(MtlService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Take out a medium-term loan — '.$year,
            'medium-term loan'
        );

        if (! $facility) {
            return;
        }

        // (1) THE MONEY — nothing yet, and that is correct.
        //
        // TRS-002 (2026-09), matching CashVero: the bank does not hand
        // an MTL over as cash. It pays the company's suppliers
        // directly out of it, so the loan is a payment SOURCE drawn
        // progressively — like a Clean Overdraft. Creating it moves no
        // money and posts nothing; the drawdown happens later, one
        // payment at a time (see mediumTermLoanDrawdown() below).
        //
        // What creation MUST do is link the facility to 211 Long-Term
        // Loans, because that link is what makes a payment from it
        // credit the loan liability at all.
        $verify->money(
            $bankBefore,
            $verify->trueBalance((int) $bank->coa_account_id),
            'Creating a medium-term loan moved money before anything was drawn',
            'Take out a medium-term loan — '.$year,
            prefix: 'TRS'
        );

        if (! $facility->coa_account_id) {
            $this->log->finding(
                prefix: 'TRS',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A new medium-term loan is not linked to a loan liability account',
                operation: 'Take out a medium-term loan — '.$year,
                expected: 'coa_account_id set to 211 Long-Term Loans, so payments from the loan credit it',
                actual: 'coa_account_id is null, so the loan cannot be paid from at all',
                source: 'MtlService::create()',
                rootCause: 'A facility is used as a payment source through its own COA account — '
                    .'SupplierPaymentService::resolveBankOrFacilityAccount() reads it. Without the link '
                    .'the facility is unusable as a money leg.',
                context: ['facility' => $facility->name, 'entry type' => 'new'],
            );
        }

        // (2) THE SCHEDULE. 24 monthly installments, straight-line
        // principal with interest on the declining balance — the
        // shape the form itself validates.
        $principal   = bcdiv($limit, '24', 2);
        $lines       = [];
        $balance     = $limit;
        $monthlyRate = bcdiv('20', '1200', 8); // 20% a year, per month

        for ($i = 1; $i <= 24; $i++) {
            $interest = bcmul($balance, $monthlyRate, 2);
            $end      = bcsub($balance, $principal, 2);

            $lines[] = [
                'due_date'          => date('Y-m-d', strtotime("{$year}-02-15 +".($i - 1).' months')),
                'beginning_balance' => $balance,
                'schedule_payment'  => bcadd($principal, $interest, 2),
                'interest_amount'   => $interest,
                'principal_amount'  => $principal,
                'end_balance'       => $end,
            ];

            $balance = $end;
        }

        $saved = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\SaveMtlScheduleRequest::class,
            ['lines' => $lines],
            fn (array $data) => app(MtlService::class)->saveSchedule($facility, $data['lines'], $ctx->actor),
            'Set the loan repayment schedule — '.$year,
            'loan schedule'
        );

        // (2b) THE DRAWDOWN. This is what an MTL actually is in this
        // product: the bank pays the company's suppliers out of the
        // loan. Drawing must credit 211 Long-Term Loans and consume
        // room against the limit — checked here with a fixed slice so
        // the figures stay readable.
        $this->drawOnMediumTermLoan($ctx, $facility, $year);

        $stored = MtlScheduleLine::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)
            ->orderBy('sort_order')
            ->get();

        $verify->truth(
            $stored->count() === 24,
            'The loan schedule did not store every installment',
            'Loan schedule — '.$year,
            ['submitted' => 24, 'stored' => $stored->count()],
            prefix: 'TRS'
        );

        if ($stored->isNotEmpty()) {
            // Each line's end balance must be the next one's opening,
            // and the last must clear. A schedule that does not close
            // leaves a loan permanently outstanding.
            $chained = true;
            $previous = null;

            foreach ($stored as $line) {
                if ($previous !== null && bccomp((string) $previous->end_balance, (string) $line->beginning_balance, 2) !== 0) {
                    $chained = false;
                }
                $previous = $line;
            }

            $verify->truth(
                $chained,
                'The loan schedule does not chain: an installment does not open where the previous one closed',
                'Loan schedule — '.$year,
                [],
                prefix: 'TRS'
            );

            $verify->money(
                '0.00',
                (string) $stored->last()->end_balance,
                'The loan schedule does not repay the loan: the final balance is not zero',
                'Loan schedule — '.$year,
                ['final end balance' => (string) $stored->last()->end_balance],
                prefix: 'TRS'
            );

            // (3) A SETTLEMENT. Pay the first installment in full and
            // the second by half, and check the remaining balance both
            // times — a partial payment is where off-by-one arithmetic
            // in a settlement engine usually shows up.
            $first  = $stored->first();
            $payment = (string) $first->schedule_payment;

            $settled = $this->gate->submit(
                \App\Http\Requests\FinancialInstitutions\RecordMtlSettlementRequest::class,
                [
                    'settlement_date'    => $first->due_date->toDateString(),
                    'settlement_amount'  => $payment,
                    'current_account_id' => $bank->id,
                ],
                fn (array $data) => app(MtlService::class)->recordSettlement($first->fresh(), $data, $ctx->actor),
                'Repay a loan installment — '.$year,
                'loan settlement'
            );

            if ($settled) {
                $verify->money(
                    '0.00',
                    $first->fresh()->remainingAmount(),
                    'A fully paid loan installment still shows an amount remaining',
                    'Loan settlement — '.$year,
                    ['installment' => $payment],
                    prefix: 'TRS'
                );

                // Overpaying the same installment must be refused.
                $this->gate->expectRefused(
                    fn () => app(MtlService::class)->recordSettlement($first->fresh(), [
                        'settlement_date'    => $first->due_date->toDateString(),
                        'settlement_amount'  => '1.00',
                        'current_account_id' => $bank->id,
                    ], $ctx->actor),
                    '/cannot exceed the remaining balance/i',
                    'Pay more than a loan installment is worth',
                    'an installment cannot be settled twice'
                );
            }

            $second = $stored->get(1);

            if ($second) {
                $half = bcdiv((string) $second->schedule_payment, '2', 2);

                $this->gate->submit(
                    \App\Http\Requests\FinancialInstitutions\RecordMtlSettlementRequest::class,
                    [
                        'settlement_date'    => $second->due_date->toDateString(),
                        'settlement_amount'  => $half,
                        'current_account_id' => $bank->id,
                    ],
                    fn (array $data) => app(MtlService::class)->recordSettlement($second->fresh(), $data, $ctx->actor),
                    'Part-pay a loan installment — '.$year,
                    'loan settlement'
                );

                $verify->money(
                    bcsub((string) $second->schedule_payment, $half, 2),
                    $second->fresh()->remainingAmount(),
                    'A part-paid loan installment shows the wrong amount remaining',
                    'Loan settlement — '.$year,
                    ['installment' => (string) $second->schedule_payment, 'paid' => $half],
                    prefix: 'TRS'
                );
            }

            // And the same question as the drawdown: did the repayment
            // reach the books? Principal and interest must split.
            $this->assertPosted(
                $ctx,
                'mtl_settlement',
                'Repaying a loan installment posts nothing to the general ledger',
                'Loan settlement — '.$year,
                'MtlService::recordSettlement()',
                'recordSettlement() carries a second comment block, "ACCOUNTING ENGINE TODO #2 — INSTALLMENT '
                    .'SETTLEMENT", describing the Dr Loan Payable / Dr Interest Expense / Cr bank entry it should '
                    .'post. Nothing is posted, so neither the principal repayment nor the interest expense ever '
                    .'reaches the accounts — the interest is missing from the income statement entirely.'
            );
        }

        $this->log->note('Medium-term loan opened, scheduled over 24 months and part-repaid.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Time deposit — open, earn interest, renew, break
    // ══════════════════════════════════════════════════════════════

    /**
     * @return BankAccount|null the deposit, for the fully secured
     *         overdraft to be secured against
     */
    /**
     * Pays a supplier bill out of the loan — the operation the whole
     * facility exists for — and checks the two things it must do:
     * credit the loan liability, and consume room against the limit.
     */
    private function drawOnMediumTermLoan(CycleContext $ctx, $facility, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $bill = \App\Models\SupplierBill::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->orderBy('id')
            ->first();

        if (! $bill || ! $facility->coa_account_id) {
            return;
        }

        $outstandingOnBill = (string) $bill->amount_outstanding;
        $draw = bccomp($outstandingOnBill, '100000.00', 2) > 0 ? '100000.00' : $outstandingOnBill;

        if (bccomp($draw, '0', 2) <= 0) {
            return;
        }

        $liabilityBefore = $verify->trueBalance((int) $facility->coa_account_id);
        $drawnBefore     = (string) $facility->fresh()->outstanding_balance;

        $payment = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierPaymentRequest::class,
            [
                'partner_id'                => $bill->partner_id,
                'payment_type'              => 'settlement',
                'payment_date'              => $year.'-02-10',
                'money_type'                => 'outgoing_transfer',
                'pay_currency_id'           => $ctx->currencyId,
                'amount'                    => $draw,
                'source_type'               => 'credit_facility',
                'source_credit_facility_id' => $facility->id,
                'settlements'               => [[
                    'supplier_bill_id'  => $bill->id,
                    'settlement_amount' => $draw,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\SupplierPaymentService::class)
                ->record($ctx->companyId, $data, $ctx->actor),
            'Pay a supplier from the medium-term loan — '.$year,
            'medium-term loan drawdown'
        );

        if (! $payment) {
            return;
        }

        // A loan is a liability: drawing on it makes the signed
        // balance more negative by exactly what was drawn.
        $verify->money(
            bcsub($liabilityBefore, $draw, 2),
            $verify->trueBalance((int) $facility->coa_account_id),
            'Paying a supplier from the loan did not increase the loan liability',
            'Pay a supplier from the medium-term loan — '.$year,
            prefix: 'TRS'
        );

        $verify->money(
            bcadd($drawnBefore, $draw, 2),
            (string) $facility->fresh()->outstanding_balance,
            'The drawdown did not consume room against the loan limit',
            'Pay a supplier from the medium-term loan — '.$year,
            prefix: 'TRS'
        );
    }

    private function timeDeposit(CycleContext $ctx, int $year): ?BankAccount
    {
        $verify  = new Verify($this->log, $ctx->companyId);
        $funding = $ctx->bankAccounts[0];
        $amount  = '500000.00';

        $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
            'parent_id'     => $this->coa($ctx, '132'),
            'use_auto_code' => true,
            'name_en'       => 'Time Deposit — '.$year,
        ], $ctx->actor);

        $fundingBefore = $verify->trueBalance((int) $funding->coa_account_id);

        $deposit = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\BankAccountRequest::class,
            [
                'bank_id'                    => $funding->bank_id,
                'account_name'               => 'Time Deposit — '.$year,
                'account_number'             => 'TD-'.$ctx->companyId.'-'.$year,
                'account_type'               => 'time_deposit',
                'currency_id'                => $ctx->currencyId,
                'coa_account_id'             => $leaf->id,
                'td_entry_type'              => 'new',
                'td_funding_bank_account_id' => $funding->id,
                'td_start_date'              => $year.'-02-01',
                'td_maturity_date'           => $year.'-11-01',
                'td_interest_rate'           => 21,
                'td_amount'                  => $amount,
                'is_active'                  => true,
            ],
            fn (array $data) => app(\App\Services\FinancialInstitutions\BankService::class)->createBankAccount(
                $ctx->companyId,
                \App\Models\FinancialInstitutions\Bank::withoutGlobalScopes()->findOrFail($funding->bank_id),
                $data,
                $ctx->actor
            ),
            'Open a time deposit — '.$year,
            'time deposit'
        );

        if (! $deposit) {
            return null;
        }

        // Opening a NEW deposit moves real cash out of the current
        // account — that is the whole difference between 'new' and
        // 'existing', so it is checked rather than assumed.
        $verify->money(
            bcsub($fundingBefore, $amount, 2),
            $verify->trueBalance((int) $funding->coa_account_id),
            'Opening a time deposit did not take the money out of the current account',
            'Time deposit — '.$year,
            ['deposit' => $amount],
            prefix: 'TRS'
        );

        $verify->money(
            $amount,
            $verify->trueBalance((int) $leaf->id),
            'The time deposit account does not hold the deposited amount',
            'Time deposit — '.$year,
            [],
            prefix: 'TRS'
        );

        // Interest is credited to the current account, not compounded
        // into the deposit.
        $interest = '26250.00';
        $currentBefore = $verify->trueBalance((int) $funding->coa_account_id);

        $applied = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\ApplyTdInterestRequest::class,
            [
                'event_date'             => $year.'-05-01',
                'interest_amount'        => $interest,
                'credit_bank_account_id' => $funding->id,
            ],
            fn (array $data) => app(TimeDepositService::class)->applyInterest($deposit, $data, $ctx->actor),
            'Credit time deposit interest — '.$year,
            'time deposit interest'
        );

        if ($applied) {
            $verify->money(
                bcadd($currentBefore, $interest, 2),
                $verify->trueBalance((int) $funding->coa_account_id),
                'Time deposit interest did not reach the current account',
                'Time deposit interest — '.$year,
                ['interest' => $interest],
                prefix: 'TRS'
            );

            $verify->money(
                $amount,
                $verify->trueBalance((int) $leaf->id),
                'Crediting interest changed the time deposit principal, which it must not',
                'Time deposit interest — '.$year,
                [],
                prefix: 'TRS'
            );

            $verify->ledgerBalanced('Time deposit interest — '.$year);
        }

        // Rolling it over.
        $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\RenewTdRequest::class,
            [
                'event_date'             => $year.'-11-01',
                'new_maturity_date'      => ($year + 1).'-08-01',
                'new_interest_rate'      => 19,
                'interest_amount'        => '0.00',
                'credit_bank_account_id' => $funding->id,
            ],
            fn (array $data) => app(TimeDepositService::class)->renew($deposit->fresh(), $data, $ctx->actor),
            'Renew a time deposit — '.$year,
            'time deposit renewal'
        );

        $this->log->note('Time deposit opened, paid interest and rolled over.');

        return $deposit->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    //  Overdraft against commercial paper
    // ══════════════════════════════════════════════════════════════

    /**
     * The facility a company borrows against its customers' post-dated
     * cheques. Its rate card prices by how far away the cheque's due
     * date is, so the tiers are what make it more than another
     * overdraft — and the calculators behind them are pure arithmetic
     * that has never been checked against a worked example.
     */
    private function commercialPaperOverdraft(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];

        $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
            'parent_id'     => $this->coa($ctx, '223'),
            'use_auto_code' => true,
            'name_en'       => 'OD Against Commercial Paper',
        ], $ctx->actor);

        $facility = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\CommercialPaperOverdraftRequest::class,
            [
                'bank_id'             => $bank->bank_id,
                'start_date'          => $year.'-01-10',
                'review_date'         => ($year + 1).'-01-10',
                'account_number'      => 'CPOD-'.$ctx->companyId.'-'.$year,
                'currency_id'         => $ctx->currencyId,
                'coa_account_id'      => $leaf->id,
                'facility_limit'      => 2000000,
                'outstanding_balance' => 0,
                'balance_date'        => $year.'-01-10',
                'borrowing_rate'      => 18,
                'bank_margin_rate'    => 2,
                'min_interest_rate'   => 1,
                'hdb_rate'            => 0.5,
                'admin_fee_rate'      => 0.25,
                'follows_corridor_rate' => false,
                'lending_tiers'       => [
                    ['due_within_days' => 30, 'lending_rate_pct' => 95],
                    ['due_within_days' => 90, 'lending_rate_pct' => 90],
                    ['due_within_days' => 180, 'lending_rate_pct' => 80],
                ],
            ],
            fn (array $data) => app(CommercialPaperOverdraftService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open an overdraft against commercial paper — '.$year,
            'commercial paper overdraft'
        );

        if (! $facility) {
            return;
        }

        $service = app(CommercialPaperOverdraftService::class);

        // Worked examples, stated here rather than derived from the
        // code, so the test fails if the formula changes.
        $verify->unitCost(
            '20.0000',
            $service->calcInterestRate('18', '2'),
            'The facility interest rate is not the borrowing rate plus the bank margin',
            'Commercial paper overdraft — '.$year,
            [],
            prefix: 'TRS'
        );

        // One day's interest on 1,000,000 at 20% a year, on the
        // 360-day year Egyptian banks price on — NOT 365. The
        // convention is stated here as a worked example so that a
        // change to it fails loudly instead of quietly repricing every
        // facility in the system.
        $verify->money(
            bcdiv(bcmul('1000000', '0.20', 6), '360', 2),
            $service->calcDailyInterest('1000000', '20'),
            'Daily facility interest is not balance × annual rate ÷ 360, the documented Egyptian convention',
            'Commercial paper overdraft — '.$year,
            ['balance' => '1000000', 'annual rate' => '20%', 'day count' => '360'],
            prefix: 'TRS'
        );

        // A facility drawn to zero costs nothing.
        $verify->money(
            '0.00',
            $service->calcDailyInterest('0', '20'),
            'An unused facility still accrues daily interest',
            'Commercial paper overdraft — '.$year,
            [],
            prefix: 'TRS'
        );

        $this->log->note('Overdraft against commercial paper opened with a three-tier rate card.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Fully secured overdraft — secured on the time deposit
    // ══════════════════════════════════════════════════════════════

    private function fullySecuredOverdraft(CycleContext $ctx, int $year, ?BankAccount $deposit): void
    {
        if (! $deposit) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];

        $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
            'parent_id'     => $this->coa($ctx, '223'),
            'use_auto_code' => true,
            'name_en'       => 'Fully Secured Overdraft',
        ], $ctx->actor);

        $facility = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\FullySecuredOverdraftRequest::class,
            [
                'bank_id'               => $bank->bank_id,
                'start_date'            => $year.'-03-01',
                'review_date'           => ($year + 1).'-03-01',
                'account_number'        => 'FSOD-'.$ctx->companyId.'-'.$year,
                'currency_id'           => $ctx->currencyId,
                'coa_account_id'        => $leaf->id,
                'fsod_td_account_id'    => $deposit->id,
                'fsod_td_currency_id'   => $ctx->currencyId,
                'fsod_td_amount'        => '500000.00',
                'fsod_td_interest_rate' => 21,
                'fsod_td_lending_pct'   => 90,
                'outstanding_balance'   => 0,
                'balance_date'          => $year.'-03-01',
                'borrowing_rate'        => 18,
                'bank_margin_rate'      => 2,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'follows_corridor_rate' => false,
            ],
            fn (array $data) => app(FullySecuredOverdraftService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open a fully secured overdraft — '.$year,
            'fully secured overdraft'
        );

        if (! $facility) {
            return;
        }

        // The limit is the collateral times the lending percentage,
        // and the form deliberately refuses to accept it from the
        // client — so the service is the only thing that can get it
        // wrong, which is exactly why it is checked.
        $verify->money(
            bcmul('500000.00', '0.90', 2),
            (string) $facility->facility_limit,
            'The secured overdraft limit is not the collateral times the lending percentage',
            'Fully secured overdraft — '.$year,
            ['collateral' => '500000.00', 'lending %' => '90'],
            prefix: 'TRS'
        );

        $this->log->note('Fully secured overdraft opened against the time deposit.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Overdraft against assigned contracts
    // ══════════════════════════════════════════════════════════════

    private function contractAssignmentOverdraft(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];

        // A bank will not lend against a contract that finishes before
        // it reviews the facility, so the collateral has to outlive the
        // review date. Pick the longest-running order the company has
        // and set the review date inside it.
        $salesOrder = \App\Models\SalesOrder::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('end_date')
            ->orderByDesc('end_date')
            ->first();

        if (! $salesOrder) {
            return;
        }

        $reviewDate = \Carbon\Carbon::parse($salesOrder->end_date)->subMonth()->toDateString();

        if ($reviewDate <= $year.'-04-01') {
            $this->log->note('  (no sales order runs long enough to pledge against an overdraft this year)');

            return;
        }

        $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
            'parent_id'     => $this->coa($ctx, '223'),
            'use_auto_code' => true,
            'name_en'       => 'OD Against Assigned Contracts',
        ], $ctx->actor);

        $facility = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\ContractAssignmentOdFacilityRequest::class,
            [
                'bank_id'                       => $bank->bank_id,
                'start_date'                    => $year.'-04-01',
                'review_date'                   => $reviewDate,
                'account_number'                => 'CAOD-'.$ctx->companyId.'-'.$year,
                'currency_id'                   => $ctx->currencyId,
                'coa_account_id'                => $leaf->id,
                'facility_limit'                => 1500000,
                'outstanding_balance'           => 0,
                'balance_date'                  => $year.'-04-01',
                'borrowing_rate'                => 18,
                'bank_margin_rate'              => 2,
                'min_interest_rate'             => 1,
                'hdb_rate'                      => 0.5,
                'admin_fee_rate'                => 0.25,
                'settled_max_days'              => 120,
                'max_lending_limit_per_contract' => 750000,
                'follows_corridor_rate'         => false,
            ],
            fn (array $data) => app(ContractAssignmentOdService::class)->create(
                $ctx->companyId,
                \App\Models\FinancialInstitutions\Bank::withoutGlobalScopes()->findOrFail($bank->bank_id),
                $data,
                $ctx->actor
            ),
            'Open an overdraft against assigned contracts — '.$year,
            'contract assignment overdraft'
        );

        if (! $facility) {
            return;
        }

        $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\ContractAssignmentOdAssignmentRequest::class,
            [
                'partner_id'       => $salesOrder->partner_id,
                'collateral_type'  => 'sales_order',
                'collateral_so_id' => $salesOrder->id,
                'assignment_date'  => $year.'-04-05',
                'lending_pct'      => 70,
            ],
            fn (array $data) => app(ContractAssignmentOdService::class)->addAssignment(
                $ctx->companyId, $facility, $data, $ctx->actor
            ),
            'Assign a contract to the overdraft — '.$year,
            'contract assignment'
        );

        // The same contract must not be assignable twice — otherwise
        // the bank lends against the same receivable more than once.
        $this->gate->expectRefused(
            fn () => app(ContractAssignmentOdService::class)->addAssignment($ctx->companyId, $facility->fresh(), [
                'partner_id'       => $salesOrder->partner_id,
                'collateral_type'  => 'sales_order',
                'collateral_so_id' => $salesOrder->id,
                'assignment_date'  => $year.'-04-06',
                'lending_pct'      => 70,
            ], $ctx->actor),
            '/already/i',
            'Assign the same contract to the overdraft twice',
            'a receivable can only be pledged once'
        );

        $verify->ledgerBalanced('Contract assignment overdraft — '.$year);
        $this->log->note('Overdraft against assigned contracts opened and a contract pledged.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Reverse factoring — the bank pays our supplier early
    // ══════════════════════════════════════════════════════════════

    private function reverseFactoring(CycleContext $ctx, int $year): void
    {
        if (! isset($ctx->factoringAgreement) || $ctx->openSupplierBills === []) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);

        // Reverse factoring is the bank paying OUR supplier early, so
        // it needs an agreement of type 'reverse' — the ordinary
        // factoring agreement (type 'direct', where the bank buys our
        // receivables) is explicitly refused.
        $agreement = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\FactoringAgreementRequest::class,
            [
                'factoring_company_id'  => $ctx->factoringAgreement->factoring_company_id,
                'agreement_reference'   => 'RFCT-CYCLE-'.$ctx->companyId,
                'agreement_type'        => 'reverse',
                'recourse_type'         => 'without_recourse',
                'borrowing_rate'        => 15,
                'discount_margin_rate'  => 2,
                'discount_rate'         => 17,
                'follows_corridor_rate' => false,
                'coa_account_id'        => $this->coa($ctx, '224001'),
                'start_date'            => $year.'-01-01',
                'end_date'              => ($year + 3).'-01-01',
            ],
            fn (array $data) => app(\App\Services\FinancialInstitutions\FactoringService::class)
                ->createAgreement($ctx->companyId, $data, $ctx->actor),
            'Open a reverse factoring agreement — '.$year,
            'reverse factoring agreement'
        );

        if (! $agreement) {
            return;
        }

        $bill   = null;

        foreach ($ctx->openSupplierBills as $candidate) {
            $candidate->refresh();

            if (bccomp((string) $candidate->amount_outstanding, '0', 2) > 0) {
                $bill = $candidate;
                break;
            }
        }

        if (! $bill) {
            return;
        }

        // Reverse factoring insists on partner.ap_account_id, which no
        // other payable path requires and the Partner form never sets
        // — the payables mirror of the AR-account gap recorded in
        // Treasury::factorAnInvoice().
        $supplier = \App\Models\Partner::withoutGlobalScopes()->find($bill->partner_id);

        if ($supplier && ! $supplier->ap_account_id) {
            $this->log->finding(
                prefix: 'TRS',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Reverse factoring requires an AP account on the supplier that nothing else requires or sets',
                operation: 'Reverse factoring — '.$year,
                expected: 'a supplier created through the normal Partner form to be reverse-factorable',
                actual: 'refused — "This supplier has no Accounts Payable account assigned"',
                source: 'ReverseFactoringTransactionService::createReverseFactoring(), and PartnerService::create() '
                    .'which never sets it',
                rootCause: 'Billing, payments and statements all fall back to the default payables account when '
                    .'partner.ap_account_id is null. Reverse factoring alone insists on it, so an ordinary supplier '
                    .'cannot be reverse-factored until someone edits the partner. Same shape as the AR-account gap '
                    .'on the receivables side.',
            );

            $supplier->update(['ap_account_id' => $this->coa($ctx, '221001')]);
        }

        $date = $year.'-06-10';

        $transaction = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\StoreReverseFactoringTransactionRequest::class,
            [
                'factoring_agreement_id'            => $agreement->id,
                'supplier_bill_id'                  => $bill->id,
                'reverse_factoring_date'            => $date,
                'reverse_due_date'                  => $year.'-09-10',
                'reverse_factoring_percentage'      => 90,
                // H-20: interest is calculated by the system from the agreement's rate.
                'reverse_factoring_charges'         => '500.00',
                'settlement_method'                 => 'due_date',
                'source_bank_account_id'            => $ctx->bankAccounts[0]->id,
            ],
            fn (array $data) => app(ReverseFactoringTransactionService::class)
                ->createReverseFactoring($ctx->companyId, $data, $ctx->actor),
            'Have the bank pay a supplier early — '.$year,
            'reverse factoring'
        );

        if (! $transaction) {
            return;
        }

        $verify->ledgerBalanced('Reverse factoring — '.$year);

        // And settle it on the due date: the company repays the bank.
        $this->gate->submit(
            null,
            [],
            fn () => app(ReverseFactoringTransactionService::class)->settle(
                $ctx->companyId,
                $transaction->fresh(),
                ['settlement_date' => $year.'-09-10', 'bank_account_id' => $ctx->bankAccounts[0]->id],
                $ctx->actor
            ),
            'Repay the reverse factoring — '.$year,
            'reverse factoring settlement'
        );

        $verify->ledgerBalanced('Reverse factoring settlement — '.$year);
        $this->log->note('Reverse factoring arranged and repaid.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Buying foreign currency
    // ══════════════════════════════════════════════════════════════

    /**
     * Selling EGP to buy USD. The two legs are in different
     * currencies, so the only thing that keeps the entry balanced is
     * the deal rate — which makes this the cheapest place in the
     * system to lose money to a rounding rule.
     */
    private function buyForeignCurrency(CycleContext $ctx, int $year): void
    {
        if (! $ctx->foreignCurrencyId || count($ctx->bankAccounts) < 2) {
            return;
        }

        $verify  = new Verify($this->log, $ctx->companyId);
        $failure = null;

        $transaction = $this->attempt(
            \App\Http\Requests\FinancialInstitutions\StoreBuySellCurrencyRequest::class,
            [
                'transaction_date'        => $year.'-07-05',
                'from_leg_type'           => 'bank',
                'from_bank_account_id'    => $ctx->bankAccounts[0]->id,
                'to_leg_type'             => 'bank',
                'to_bank_account_id'      => $ctx->bankAccounts[1]->id,
                'currency_to_sell_id'     => $ctx->currencyId,
                'currency_to_sell_amount' => '300000.00',
                'currency_to_buy_id'      => $ctx->foreignCurrencyId,
                'currency_to_buy_amount'  => '10000.00',
                'deal_rate'               => 30,
            ],
            fn (array $data) => app(BuySellCurrencyService::class)->create($ctx->companyId, $data, $ctx->actor),
            'currency deal',
            $failure
        );

        if (! $transaction) {
            // Saving a currency deal is the FIRST thing the screen
            // does, so if it cannot be saved the whole module is
            // unreachable — worth naming rather than leaving as one
            // more failed operation.
            $this->log->finding(
                prefix: 'TRS',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A foreign currency deal cannot be saved at all',
                operation: 'Buy foreign currency — '.$year,
                expected: 'the deal to save as a draft, the way the screen describes',
                actual: 'the insert is rejected by the database before any business rule runs',
                source: 'BuySellCurrencyService::create() and migration 000141',
                rootCause: 'buy_sell_currencies.sell_reference_rate and .buy_reference_rate are declared NOT NULL '
                    .'with no default, and create() never sets them — only post() does. The controller passes '
                    .'$request->validated() straight through and StoreBuySellCurrencyRequest has no rule for either '
                    .'field, so there is no path through the UI that supplies them. setToDraft() then writes NULL '
                    .'into the same two columns, so that action is blocked by the same constraint from the other '
                    .'direction.',
                evidence: 'The INSERT issued by the exact code path the Save button uses is rejected: '
                    .($failure?->getMessage() ?? 'no exception was captured'),
            );

            return;
        }

        $posted = $this->gate->submit(
            null,
            [],
            fn () => app(BuySellCurrencyService::class)->post($transaction->fresh(), $ctx->actor),
            'Post a foreign currency deal — '.$year,
            'currency deal posting'
        );

        if ($posted) {
            $verify->ledgerBalanced('Currency deal — '.$year);
            $verify->noCrossCompanyPostings('Currency deal — '.$year);
        }

        $this->log->note('Foreign currency bought at the deal rate and posted.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * Like FormGate::submit(), but hands the exception back instead of
     * filing a generic "an operation failed" finding — for the cases
     * where this scenario knows exactly what the failure means and
     * wants to say so once, precisely, rather than twice, vaguely.
     */
    private function attempt(string $requestClass, array $payload, callable $operation, string $documentKind, ?\Throwable &$failure = null): mixed
    {
        try {
            $result = $operation($payload);
        } catch (\Throwable $e) {
            $failure = $e;

            return null;
        }

        $this->log->operation($documentKind);

        return $result;
    }

    /**
     * "The operation succeeded" and "the money reached the books" are
     * different claims. This asserts the second.
     */
    private function assertPosted(
        CycleContext $ctx,
        string $sourceType,
        string $title,
        string $operation,
        string $source,
        string $rootCause,
    ): void {
        $exists = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('source_type', $sourceType)
            ->exists();

        if ($exists) {
            $this->log->checkPassed();

            return;
        }

        $this->log->finding(
            prefix: 'TRS',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $title,
            operation: $operation,
            expected: 'a journal entry recording the money that moved',
            actual: "no journal entry with source type '{$sourceType}' exists for this company",
            source: $source,
            rootCause: $rootCause,
        );
    }

    private function coa(CycleContext $ctx, string $code): ?int
    {
        return ChartOfAccount::findByCode($ctx->companyId, $code)?->id;
    }
}
