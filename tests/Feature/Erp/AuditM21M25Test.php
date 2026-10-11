<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM21M25Test
//
//   M-21  Leases: the current portion is moved out of the long-term
//         liability at month-end (and reversed on day one); the final
//         purchase clears the right-of-use accounts completely; each
//         real cheque is linked to the schedule instalment it pays.
//   M-22  Loans: interest earned but unpaid is accrued at month-end,
//         the current portion is moved to 225, and a schedule that does
//         not add up is refused.
//   M-23  Overdraft interest is accrued at month-end from the daily
//         rows, less what the bank statement already posted; running it
//         twice posts it once.
//   M-24  A back-dated corridor rate leaves a true-up record, re-prices
//         only the days it governs, and does not overwrite the current
//         rate with an old one.
//   M-25  The daily job fills in days it missed.
//
//  Every expected figure is worked out by hand in the comments.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\FacilityPeriodEndEntry;
use App\Models\FinancialInstitutions\FacilityRateTrueup;
use App\Models\FinancialInstitutions\LeaseAmortizationLine;
use App\Models\FinancialInstitutions\LeaseCheque;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Services\FinancialInstitutions\BorrowingRateService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\FacilityPeriodEndService;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FinancialInstitutions\LeaseAmortizationService;
use App\Services\FinancialInstitutions\LeasingService;
use App\Services\FinancialInstitutions\MtlService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM21M25Test extends ErpTestCase
{
    private ErpScenario $s;
    private $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s    = (new ErpScenario($this->actor))->bootstrap(1);
        $this->bank = $this->s->createBankAccount('Facility Bank');
    }

    // ── Arrange ───────────────────────────────────────────────────

    /** 100,000 drawn on 1 Jan 2026, 18% corridor + 3% margin = 21%. */
    private function overdraft(bool $followsCorridor = false): CreditFacility
    {
        return app(CleanOverdraftService::class)->create($this->s->companyId, [
            'bank_id'               => $this->bank->bank_id,
            'start_date'            => '2026-01-01',
            'review_date'           => '2029-01-01',
            'account_number'        => 'OD-M25-'.random_int(1000, 9999),
            'currency_id'           => $this->s->currencyId,
            'coa_account_id'        => $this->s->coaId('223001'),
            'bank_account_id'       => $this->bank->id,
            'facility_limit'        => 500000,
            'outstanding_balance'   => 100000,
            'balance_date'          => '2026-01-01',
            'borrowing_rate'        => 18,
            'bank_margin_rate'      => 3,
            'min_interest_rate'     => 1,
            'hdb_rate'              => 0.5,
            'admin_fee_rate'        => 0.25,
            'settled_max_days'      => 90,
            'follows_corridor_rate' => $followsCorridor,
            'purpose'               => 'Working capital',
        ], $this->actor);
    }

    private function loan(string $entryType = 'existing'): CreditFacility
    {
        return app(MtlService::class)->create($this->s->companyId, [
            'bank_id'              => $this->bank->bank_id,
            'name'                 => 'Expansion Loan',
            'entry_type'           => $entryType,
            'start_date'           => '2026-01-15',
            'review_date'          => '2028-01-15',
            'currency_id'          => $this->s->currencyId,
            'facility_limit'       => '1200000.00',
            'account_number'       => 'MTL-M22-'.random_int(1000, 9999),
            'borrowing_rate'       => 18,
            'bank_margin_rate'     => 2,
            'tenure_months'        => 24,
            'installment_interval' => 'monthly',
            'bank_account_id'      => $this->bank->id,
        ], $this->actor);
    }

    /**
     * Three instalments of 40,000 principal. The first period is not
     * day-counted (there is no earlier due date). The later interest
     * is the 20% / 360-day figure, which is what a typed amount must
     * match: 1,160,000 × 20% × 28 ÷ 360 = 18,044.44 and
     * 1,120,000 × 20% × 31 ÷ 360 = 19,288.88.
     */
    private function threeInstalments(CreditFacility $loan): void
    {
        app(MtlService::class)->saveSchedule($loan, [
            ['due_date' => '2026-02-15', 'beginning_balance' => '1200000.00', 'schedule_payment' => '50000.00', 'interest_amount' => '10000.00', 'principal_amount' => '40000.00', 'end_balance' => '1160000.00'],
            ['due_date' => '2026-03-15', 'beginning_balance' => '1160000.00', 'schedule_payment' => '58044.44', 'interest_amount' => '18044.44', 'principal_amount' => '40000.00', 'end_balance' => '1120000.00'],
            ['due_date' => '2026-04-15', 'beginning_balance' => '1120000.00', 'schedule_payment' => '59288.88', 'interest_amount' => '19288.88', 'principal_amount' => '40000.00', 'end_balance' => '1080000.00'],
        ], $this->actor);
    }

    private function refused(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('Expected the action to be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    /** Debit (or credit) one entry carries on one account code. */
    private function legOf(int $entryId, string $code, string $side): string
    {
        $row = DB::selectOne(
            "select coalesce(sum(l.{$side}), 0) as v from journal_entry_lines l
               join chart_of_accounts a on a.id = l.chart_of_account_id
              where l.journal_entry_id = ? and a.code = ?",
            [$entryId, $code]
        );

        return number_format((float) $row->v, 2, '.', '');
    }

    private function periodEnd(): FacilityPeriodEndService
    {
        return app(FacilityPeriodEndService::class);
    }

    // ── M-25 ──────────────────────────────────────────────────────

    public function test_the_daily_job_fills_in_days_it_missed(): void
    {
        $od = $this->overdraft();
        $this->assertSame(1, FacilityDailyBalance::withoutGlobalScopes()->where('credit_facility_id', $od->id)->count());

        // The job last ran on 1 Jan; it is now 10 Jan — nine days missing.
        $filled = app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-10');

        $rows = FacilityDailyBalance::withoutGlobalScopes()->where('credit_facility_id', $od->id)->orderBy('balance_date')->get();
        $this->assertSame(9, $filled);
        $this->assertCount(10, $rows);
        foreach ($rows as $row) {
            $this->assertSame('100000.00', (string) $row->closing_balance, 'The balance carries forward through the gap.');
            // 100,000 × 21% ÷ 360 = 58.333333 a day
            $this->assertSame('58.333333', (string) $row->daily_interest_amount);
        }

        $this->assertSame(0, app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-10'), 'Running it twice adds nothing.');
    }

    // ── M-23 ──────────────────────────────────────────────────────

    public function test_overdraft_interest_is_accrued_at_month_end_and_reversed_on_day_one(): void
    {
        $od = $this->overdraft();
        app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-31');

        // 31 days × 58.333333 = 1,808.333323 → 1,808.33
        $row = $this->periodEnd()->postOdInterestAccrual($od, '2026-01-31', $this->actor);

        $this->assertSame('1808.33', (string) $row->amount);
        $this->assertSame('2026-01-31', $row->journalEntry->entry_date->toDateString());
        $this->assertSame('2026-02-01', $row->reversalJournalEntry->entry_date->toDateString());
        $this->assertSame('1808.33', $this->legOf($row->journal_entry_id, '555', 'debit'));
        $this->assertSame('1808.33', $this->legOf($row->journal_entry_id, '228', 'credit'));

        // Posted once, then taken straight back out.
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '555'));
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '228'));
        $this->assertLedgerBalanced($this->s->companyId);

        $this->assertNull($this->periodEnd()->postOdInterestAccrual($od, '2026-01-31', $this->actor), 'The same month is not accrued twice.');
        $this->assertSame(1, FacilityPeriodEndEntry::withoutGlobalScopes()->where('credit_facility_id', $od->id)->count());
    }

    public function test_interest_the_bank_statement_already_posted_is_not_accrued_again(): void
    {
        $od = $this->overdraft();
        app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-31');

        // The statement for January was posted at 1,800.00 (label "2026-01").
        FacilityLiabilityCharge::create([
            'company_id' => $this->s->companyId, 'credit_facility_id' => $od->id, 'bank_account_id' => $this->bank->id,
            'charge_type' => 'interest', 'period_label' => '2026-01', 'charge_date' => '2026-01-31',
            'theoretical_amount' => '1808.33', 'actual_amount' => '1800.00', 'settled_amount' => '0.00',
            'remaining_amount' => '1800.00', 'difference_amount' => '-8.33', 'status' => 'posted',
            'posted_by' => $this->actor->id, 'posted_at' => now(),
        ]);

        // 1,808.333323 theoretical − 1,800.00 posted = 8.333323 → 8.33
        $row = $this->periodEnd()->postOdInterestAccrual($od, '2026-01-31', $this->actor);
        $this->assertSame('8.33', (string) $row->amount);
    }

    public function test_a_month_end_entry_can_be_cancelled_with_a_reason(): void
    {
        $od = $this->overdraft();
        app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-31');
        $row = $this->periodEnd()->postOdInterestAccrual($od, '2026-01-31', $this->actor);

        $this->refused(fn () => $this->periodEnd()->cancel($row, ' ', $this->actor), 'reason');

        $cancelled = $this->periodEnd()->cancel($row, 'Wrong month', $this->actor);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '555'));
        $this->assertLedgerBalanced($this->s->companyId);

        // Cancelled → it can be run again.
        $this->assertNotNull($this->periodEnd()->postOdInterestAccrual($od, '2026-01-31', $this->actor));
    }

    public function test_only_a_month_end_that_has_passed_can_be_posted(): void
    {
        $od = $this->overdraft();
        $this->refused(fn () => $this->periodEnd()->postOdInterestAccrual($od, '2026-01-30', $this->actor), 'last day of a month');
        $this->refused(fn () => $this->periodEnd()->postOdInterestAccrual($od, now()->addYear()->endOfMonth()->toDateString(), $this->actor), 'before that month has ended');
    }

    public function test_the_company_run_posts_each_item_and_reports_a_clean_summary(): void
    {
        $od = $this->overdraft();
        $this->loan('existing');

        $result = $this->periodEnd()->runForCompany($this->s->companyId, '2026-01-31', $this->actor);

        $this->assertSame([], $result['failed'], implode('; ', $result['failed']));
        $this->assertNotEmpty($result['posted']);
        $this->assertTrue(
            FacilityPeriodEndEntry::withoutGlobalScopes()->where('credit_facility_id', $od->id)->where('kind', 'od_interest_accrual')->exists()
        );
        $this->assertLedgerBalanced($this->s->companyId);
    }

    // ── M-22 ──────────────────────────────────────────────────────

    public function test_a_schedule_that_does_not_add_up_is_refused(): void
    {
        $loan = $this->loan();
        $row  = ['due_date' => '2026-02-15', 'beginning_balance' => '1200000.00', 'schedule_payment' => '50000.00', 'interest_amount' => '10000.00', 'principal_amount' => '40000.00', 'end_balance' => '1160000.00'];

        // Interest + principal ≠ payment
        $this->refused(fn () => app(MtlService::class)->saveSchedule($loan, [array_merge($row, ['schedule_payment' => '55000.00'])], $this->actor), 'does not equal the payment');
        // Beginning − principal ≠ end
        $this->refused(fn () => app(MtlService::class)->saveSchedule($loan, [array_merge($row, ['end_balance' => '1150000.00'])], $this->actor), 'does not equal the end balance');
        // Second instalment does not continue from the first
        $this->refused(fn () => app(MtlService::class)->saveSchedule($loan, [
            $row,
            array_merge($row, ['due_date' => '2026-03-15', 'beginning_balance' => '1100000.00', 'end_balance' => '1060000.00']),
        ], $this->actor), 'does not continue');
        // Dates out of order
        $this->refused(fn () => app(MtlService::class)->saveSchedule($loan, [
            $row,
            array_merge($row, ['due_date' => '2026-01-15', 'beginning_balance' => '1160000.00', 'end_balance' => '1120000.00']),
        ], $this->actor), 'date order');

        $this->assertSame(0, MtlScheduleLine::withoutGlobalScopes()->where('credit_facility_id', $loan->id)->count(), 'Nothing was saved.');

        $this->threeInstalments($loan);
        $this->assertSame(3, MtlScheduleLine::withoutGlobalScopes()->where('credit_facility_id', $loan->id)->count());
    }

    public function test_loan_interest_earned_but_unpaid_is_accrued(): void
    {
        $loan = $this->loan();
        $this->threeInstalments($loan);
        $svc = app(MtlService::class);

        // 31 Jan: only instalment 1's period (15 Jan → 15 Feb, 31 days) has started;
        // 16 days elapsed → 10,000 × 16 ÷ 31 = 5,161.29
        $this->assertSame('5161.29', $svc->accruedInterestAsOf($loan, '2026-01-31'));

        // 28 Feb: instalment 1 is due and unpaid → 10,000;
        // instalment 2 (15 Feb → 15 Mar, 28 days), 13 elapsed → 18,044.44 × 13 ÷ 28 = 8,377.78
        $this->assertSame('18377.78', $svc->accruedInterestAsOf($loan, '2026-02-28'));

        $row = $this->periodEnd()->postMtlInterestAccrual($loan, '2026-01-31', $this->actor);
        $this->assertSame('5161.29', $this->legOf($row->journal_entry_id, '551', 'debit'));
        $this->assertSame('5161.29', $this->legOf($row->journal_entry_id, '228', 'credit'));
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '551'), 'Reversed on 1 February.');
    }

    public function test_the_current_portion_of_a_loan_moves_to_225(): void
    {
        $existing = $this->loan('existing');
        $this->threeInstalments($existing);

        // All three instalments fall within 12 months of 31 Jan → 3 × 40,000
        $this->assertSame('120000.00', app(MtlService::class)->currentPortionAsOf($existing, '2026-01-31'));

        $row = $this->periodEnd()->postMtlCurrentPortion($existing, '2026-01-31', $this->actor);
        $this->assertSame('120000.00', $this->legOf($row->journal_entry_id, '211', 'debit'));
        $this->assertSame('120000.00', $this->legOf($row->journal_entry_id, '225', 'credit'));
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '225'), 'Reversed on 1 February.');
        $this->assertLedgerBalanced($this->s->companyId);
    }

    public function test_a_new_loan_never_shows_more_current_portion_than_it_has_drawn(): void
    {
        $new = $this->loan('new');
        $this->threeInstalments($new);

        // Nothing has been drawn yet, so nothing is owed.
        $this->assertSame('0.00', app(MtlService::class)->currentPortionAsOf($new, '2026-01-31'));
        $this->assertNull($this->periodEnd()->postMtlCurrentPortion($new, '2026-01-31', $this->actor));
    }

    // ── M-24 ──────────────────────────────────────────────────────

    public function test_a_back_dated_rate_leaves_a_true_up_and_reprices_only_its_own_days(): void
    {
        $od = $this->overdraft(followsCorridor: true);
        app(FacilitySettlementEngine::class)->catchUpDailyBalances($od, '2026-01-31');

        // January's statement was already posted at 1,800.00.
        FacilityLiabilityCharge::create([
            'company_id' => $this->s->companyId, 'credit_facility_id' => $od->id, 'bank_account_id' => $this->bank->id,
            'charge_type' => 'interest', 'period_label' => '2026-01', 'charge_date' => '2026-01-31',
            'theoretical_amount' => '1808.33', 'actual_amount' => '1800.00', 'settled_amount' => '0.00',
            'remaining_amount' => '1800.00', 'difference_amount' => '-8.33', 'status' => 'posted',
            'posted_by' => $this->actor->id, 'posted_at' => now(),
        ]);

        $svc = app(BorrowingRateService::class);

        // Corridor moves to 20% from 11 Jan (back-dated); with the 3% margin the facility pays 23%.
        $svc->updateCorridorRate($this->s->companyId, ['rate' => '20', 'effective_date' => '2026-01-11'], $this->actor);

        // 11 Jan → 31 Jan = 21 days.
        //   old: 21 × 58.333333 = 1,224.999993
        //   new: 100,000 × 23% ÷ 360 = 63.888888 a day → 21 × 63.888888 = 1,341.666648
        $trueup = FacilityRateTrueup::withoutGlobalScopes()->where('credit_facility_id', $od->id)->firstOrFail();
        $this->assertSame('1224.999993', (string) $trueup->old_theoretical);
        $this->assertSame('1341.666648', (string) $trueup->new_theoretical);
        $this->assertSame('116.666655', (string) $trueup->difference);
        $this->assertSame('1800.00', (string) $trueup->posted_interest);
        $this->assertTrue($trueup->affects_posted_months);
        $this->assertStringContainsString('back-dated', $svc->successMessage());

        // The 10 days before the effective date keep their old interest.
        $before = FacilityDailyBalance::withoutGlobalScopes()->where('credit_facility_id', $od->id)->where('balance_date', '2026-01-10')->first();
        $this->assertSame('58.333333', (string) $before->daily_interest_amount);

        // The facility now runs at 20 + 3.
        $this->assertSame('23.0000', (string) $od->fresh()->interest_rate);

        // An older rate entered afterwards (5 Jan) must not overwrite today's rate,
        // and must only re-price 5 Jan → 10 Jan (the 20% rate takes over on the 11th).
        $svc->updateCorridorRate($this->s->companyId, ['rate' => '19', 'effective_date' => '2026-01-05'], $this->actor);

        $this->assertSame('23.0000', (string) $od->fresh()->interest_rate, 'The current rate is untouched.');

        $day9  = FacilityDailyBalance::withoutGlobalScopes()->where('credit_facility_id', $od->id)->where('balance_date', '2026-01-09')->first();
        $day11 = FacilityDailyBalance::withoutGlobalScopes()->where('credit_facility_id', $od->id)->where('balance_date', '2026-01-11')->first();
        // 19 + 3 = 22%: 100,000 × 22% ÷ 360 = 61.111111
        $this->assertSame('61.111111', (string) $day9->daily_interest_amount);
        // 11 Jan stays on the 23% rate.
        $this->assertSame('63.888888', (string) $day11->daily_interest_amount);
    }

    public function test_a_rate_that_changes_nothing_in_the_past_leaves_no_true_up(): void
    {
        $od = $this->overdraft(followsCorridor: true);
        app(BorrowingRateService::class)->updateCorridorRate(
            $this->s->companyId,
            ['rate' => '20', 'effective_date' => now()->toDateString()],
            $this->actor
        );

        $this->assertSame(0, FacilityRateTrueup::withoutGlobalScopes()->where('credit_facility_id', $od->id)->count());
        $this->assertSame('23.0000', (string) $od->fresh()->interest_rate);
    }

    // ── M-21 ──────────────────────────────────────────────────────

    /** 12 monthly instalments of 1,000 at 12%, first due 1 Feb 2026. */
    private function lease(array $cheques = []): LeasingContract
    {
        $company = app(LeasingService::class)->createCompany($this->s->companyId, ['name_en' => 'Lease Co', 'is_active' => true], $this->actor);

        $contract = app(LeasingService::class)->createContract($this->s->companyId, [
            'leasing_company_id'        => $company->id,
            'contract_reference'        => 'LSE-M21-'.random_int(1000, 9999),
            'entry_type'                => 'new',
            'contract_type'             => 'operational',
            'asset_description'         => 'Delivery van',
            'currency_id'               => $this->s->currencyId,
            'exchange_rate'             => 1,
            'accounting_treatment'      => 'rou_b',
            'total_contract_value'      => 12000,
            'installment_interval'      => 'monthly',
            'installment_amount'        => 1000,
            'duration_months'           => 12,
            'borrowing_rate'            => 12,
            'start_date'                => '2026-01-01',
            'first_payment_date'        => '2026-02-01',
            'total_financed_value'      => 12000,
            'payment_frequency'         => 'monthly',
            'bank_liability_account_id' => $this->s->coaId('225'),
            'bank_account_id'           => $this->bank->id,
            'cheques'                   => $cheques,
        ], $this->actor);

        return app(LeaseAmortizationService::class)->activate($contract, $this->actor);
    }

    public function test_the_current_portion_of_a_lease_moves_to_234(): void
    {
        $contract = $this->lease();

        // Every instalment (1 Feb 2026 – 1 Jan 2027) falls within 12 months of 31 Jan.
        $principal = $contract->amortizationLines->reduce(fn ($c, $l) => bcadd($c, (string) $l->principal_amount, 2), '0.00');

        $row = $this->periodEnd()->postLeaseCurrentPortion($contract, '2026-01-31', $this->actor);

        $this->assertSame($principal, (string) $row->amount);
        $this->assertSame($principal, $this->legOf($row->journal_entry_id, '213', 'debit'));
        $this->assertSame($principal, $this->legOf($row->journal_entry_id, '234', 'credit'));
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '234'), 'Reversed on 1 February.');
        $this->assertLedgerBalanced($this->s->companyId);
        $this->assertNull($this->periodEnd()->postLeaseCurrentPortion($contract, '2026-01-31', $this->actor), 'Not posted twice for the same month.');
    }

    public function test_the_final_purchase_leaves_nothing_behind_on_the_right_of_use_accounts(): void
    {
        $contract = $this->lease();
        $svc = app(LeaseAmortizationService::class);

        $svc->postMonthlyWearDown($contract, $this->actor, Carbon::parse('2026-02-01'));
        $svc->postMonthlyWearDown($contract->fresh(), $this->actor, Carbon::parse('2026-03-01'));

        $contract = $contract->fresh();
        $cost  = bcadd((string) $contract->initial_rou_value, '0', 2);
        $accum = bcadd((string) \App\Models\FinancialInstitutions\LeaseWearDownEntry::withoutGlobalScopes()
            ->where('leasing_contract_id', $contract->id)->sum('amount'), '0', 2);
        $nbv   = bcsub($cost, $accum, 2);

        // The wrong amount is refused, and says what the right one is.
        $this->refused(fn () => $svc->triggerFinalPurchase($contract, $this->actor, '1.00', '2026-03-31'), $nbv);

        $svc->triggerFinalPurchase($contract->fresh(), $this->actor, $nbv, '2026-03-31');
        $contract = $contract->fresh();

        $this->assertSame('0.00', $this->glBalanceById($this->s->companyId, $contract->rou_asset_account_id), 'ROU cost cleared.');
        $this->assertSame('0.00', $this->glBalanceById($this->s->companyId, $contract->rou_amort_account_id), 'ROU amortization cleared.');
        $this->assertSame($cost, $this->glBalanceById($this->s->companyId, $contract->fixed_asset_account_id), 'Owned asset at cost.');
        $this->assertSame('-'.$accum, $this->glBalanceById($this->s->companyId, $contract->accumulated_depreciation_account_id), 'Amortization carried over.');
        $this->assertSame('closed', $contract->status);
        $this->assertLedgerBalanced($this->s->companyId);
    }

    public function test_each_cheque_is_linked_to_the_instalment_it_pays(): void
    {
        $contract = $this->lease([
            ['payment_date' => '2026-02-03', 'cheque_number' => 'CH-1', 'cheque_amount' => '1000.00', 'principal_amount' => '900.00', 'interest_amount' => '100.00'],
            ['payment_date' => '2026-03-02', 'cheque_number' => 'CH-2', 'cheque_amount' => '999.00',  'principal_amount' => '900.00', 'interest_amount' => '99.00'],
            ['payment_date' => '2027-06-15', 'cheque_number' => 'CH-X', 'cheque_amount' => '1000.00', 'principal_amount' => '900.00', 'interest_amount' => '100.00'],
        ]);

        $lines = LeaseAmortizationLine::withoutGlobalScopes()->where('leasing_contract_id', $contract->id)->orderBy('payment_date')->get();
        $cheque = fn (string $no) => LeaseCheque::withoutGlobalScopes()->where('leasing_contract_id', $contract->id)->where('cheque_number', $no)->firstOrFail();

        $this->assertSame($lines[0]->id, $cheque('CH-1')->lease_amortization_line_id, '3 Feb pays the 1 Feb instalment.');
        $this->assertSame($lines[1]->id, $cheque('CH-2')->lease_amortization_line_id, '2 Mar pays the 1 Mar instalment.');
        $this->assertNull($cheque('CH-X')->lease_amortization_line_id, 'A cheque far from every instalment stays unlinked.');

        // The schedule says 1,000.00 for March; the cheque was written for 999.00.
        $diffs = app(LeaseAmortizationService::class)->chequeScheduleDifferences($contract);
        $this->assertCount(1, $diffs);
        $this->assertSame('CH-2', $diffs[0]['cheque_number']);
        $this->assertSame('-1.00', $diffs[0]['difference']);
    }
}
