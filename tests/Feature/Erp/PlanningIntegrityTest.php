<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  PlanningIntegrityTest
//
//  REGRESSION GUARDS — audit findings PLN-001 and PLN-002, both
//  fixed 2026-09, plus SEED-001.
//
//  PLN-001  "Import existing team" threw an ambiguous-column error on
//           every company that had payroll profiles.
//  PLN-002  Projected balance sheets did not balance, from month 0,
//           by exactly the opening position the plan never carried —
//           and the screens showed only each year's last month, so a
//           plan out by a constant every single month still displayed
//           a green "Balanced" badge. Both halves are guarded here.
//  SEED-001 Demo seeders returned exit code 0 even when steps failed.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Services\Planning\PlanCalculationService;
use App\Services\Planning\PlanManpowerService;
use App\Services\Planning\PlanService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class PlanningIntegrityTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    private function makePlan(string $type = Plan::TYPE_ANNUAL, int $years = 1): Plan
    {
        return app(PlanService::class)->create($this->scenario->companyId, $type, [
            'name'                         => 'Test Plan',
            'currency_id'                  => $this->scenario->currencyId,
            'start_date'                   => '2027-01-01',
            'duration_years'               => $years,
            'business_type'                => 'trading',
            'corporate_tax_rate'           => 22.5,
            'manpower_annual_increase_pct' => 10,
            'opening_balance_source'       => Plan::OB_SOURCE_AUTO,
            'wacc_pct'                     => 15,
            'perpetual_growth_pct'         => 3,
            'valuation_basis'              => 'ebitda',
            'valuation_multiplier'         => 6,
        ], $this->actor);
    }

    // ══════════════════════════════════════════════════════════════
    //  PLN-001
    // ══════════════════════════════════════════════════════════════

    /**
     * REGRESSION GUARD — audit finding PLN-001, FIXED 2026-09.
     *
     * snapshotExistingTeam() filters on is_active and joins partners.
     * BOTH tables carry an is_active column, so the unqualified
     * `where('is_active', true)` made MySQL reject the whole query:
     *
     *     Column 'is_active' in where clause is ambiguous
     *
     * That is every company with payroll profiles — the only ones for
     * which the feature does anything at all. One word: the column is
     * now qualified with its table.
     *
     * Two employees are created on the same job title so the guard
     * also proves the grouping still works, not merely that the query
     * runs: headcount 2, average of 9,000 and 11,000 = 10,000.
     */
    public function test_importing_the_existing_team_no_longer_throws_on_an_ambiguous_column(): void
    {
        $this->createPayrollProfile('Ahmed Salah', 'Accountant', '9000.00');
        $this->createPayrollProfile('Mona Adel', 'Accountant', '11000.00');

        $plan = $this->makePlan();

        $titles = app(PlanManpowerService::class)->snapshotExistingTeam($plan, $this->actor);

        $this->assertCount(
            1, $titles,
            'Two employees sharing one job title must collapse into one plan title.'
        );

        $title = $titles[0];

        $this->assertSame('Accountant', $title->job_title);
        $this->assertSame(2, (int) $title->existing_headcount);
        $this->assertMoneyEquals(
            '10000.00',
            (string) $title->avg_current_salary,
            'Average of 9,000.00 and 11,000.00.'
        );
        $this->assertTrue((bool) $title->is_existing);
    }

    /**
     * An inactive profile must be excluded — which is the whole point
     * of the filter that was ambiguous. If the fix had qualified the
     * wrong table this test would catch it, because partners.is_active
     * stays true while the payroll profile is deactivated.
     */
    public function test_the_filter_excludes_inactive_payroll_profiles(): void
    {
        $this->createPayrollProfile('Active Person', 'Engineer', '15000.00');
        $inactive = $this->createPayrollProfile('Left The Company', 'Engineer', '25000.00');

        \App\Models\EmployeePayrollProfile::withoutGlobalScopes()
            ->where('id', $inactive->id)->update(['is_active' => false]);

        $titles = app(PlanManpowerService::class)
            ->snapshotExistingTeam($this->makePlan(), $this->actor);

        $this->assertCount(1, $titles);
        $this->assertSame(1, (int) $titles[0]->existing_headcount);
        $this->assertMoneyEquals(
            '15000.00',
            (string) $titles[0]->avg_current_salary,
            'The 25,000.00 leaver must not drag the average up — proving the filter '.
            'is on employee_payroll_profiles.is_active, not partners.is_active.'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  PLN-002
    // ══════════════════════════════════════════════════════════════

    /**
     * REGRESSION GUARD — audit finding PLN-002, FIXED 2026-09.
     *
     * The builder took two figures from the company's real opening
     * position — cash and total equity — and dropped the rest, even
     * though openingFigures() computes AR, inventory and AP as well.
     * Every projected month was therefore out by the same constant,
     * visible from month 0 before the plan had done anything.
     *
     * EVERY month must tie out, not just the ones a screen samples.
     */
    public function test_every_projected_month_balances(): void
    {
        $this->tradeSoThatTheCompanyHasARealOpeningPosition();

        $results = app(PlanCalculationService::class)->calculate($this->makePlan(), bypassCache: true);

        $this->assertNotEmpty($results['bs'], 'The plan must project at least one month.');

        $unbalanced = [];
        foreach ($results['bs'] as $month => $row) {
            if (! $row['is_balanced']) {
                $unbalanced[] = "month {$month} out by {$row['difference']}";
            }
        }

        $this->assertSame(
            [], $unbalanced,
            'PLN-002 fix: Assets = Liabilities + Equity in every projected month. '.
            'Failing months: '.implode('; ', $unbalanced)
        );
    }

    /**
     * Month 0 specifically — the month that identified the defect,
     * because a plan that is already out before it has projected
     * anything can only be carrying its opening position wrongly.
     */
    public function test_month_zero_balances_exactly(): void
    {
        $this->tradeSoThatTheCompanyHasARealOpeningPosition();

        $results = app(PlanCalculationService::class)->calculate($this->makePlan(), bypassCache: true);

        $this->assertMoneyEquals(
            '0.00',
            (string) $results['bs'][0]['difference'],
            'PLN-002 fix: month 0 ties out exactly. This is the month that used to '.
            'carry the whole opening imbalance.'
        );
    }

    /**
     * The opening position must actually be carried — not merely
     * cancelled out by two errors of equal size.
     *
     * This asserts the plan's own opening figures are non-zero and
     * that the balance sheet reflects them, so a future change that
     * "fixes" the tie-out by zeroing everything would fail here.
     */
    public function test_the_opening_position_is_genuinely_carried_not_zeroed(): void
    {
        $this->tradeSoThatTheCompanyHasARealOpeningPosition();

        $plan = $this->makePlan();
        $opening = app(\App\Services\Planning\PlanOpeningBalanceService::class)->openingFigures($plan);

        $this->assertSame(
            1,
            bccomp($opening['inventory'], '0', 2),
            'Precondition: the company must genuinely hold inventory, or this test '.
            'cannot tell a carried balance from a dropped one.'
        );

        $results = app(PlanCalculationService::class)->calculate($plan, bypassCache: true);
        $monthZero = $results['bs'][0];

        $this->assertMoneyEquals(
            $opening['inventory'],
            (string) $monthZero['inventory'],
            'PLN-002 fix: opening inventory appears on the projected balance sheet. '.
            'It used to be computed and then silently discarded.'
        );

        $this->assertMoneyEquals(
            $opening['accounts_payable'],
            (string) $monthZero['total_liabilities'],
            'And opening accounts payable is the Liabilities line, which used to be '.
            'hardcoded to 0.00.'
        );
    }

    /**
     * The second half of PLN-002: the screens read a per-month
     * verdict now.
     *
     * annual.bs[y] is that year's LAST month, so the old check could
     * pass while eleven months of the same year were out. balance_check
     * covers every month the plan projects.
     */
    public function test_the_engine_reports_a_verdict_for_every_month_not_just_year_ends(): void
    {
        $this->tradeSoThatTheCompanyHasARealOpeningPosition();

        $plan = $this->makePlan(Plan::TYPE_BUSINESS_PLAN, years: 5);
        $results = app(PlanCalculationService::class)->calculate($plan, bypassCache: true);

        $this->assertArrayHasKey('balance_check', $results);

        $this->assertSame(
            count($results['bs']),
            $results['balance_check']['months_checked'],
            'Every projected month must be checked — 60 for a five-year plan, not the '.
            '5 year-end months the screens used to read.'
        );

        $this->assertGreaterThan(
            count($results['annual']['bs']),
            $results['balance_check']['months_checked'],
            'Guard the guard: the monthly check must cover strictly more months than '.
            'the annual view, or it is not adding anything.'
        );

        $this->assertTrue($results['balance_check']['all_balanced']);
        $this->assertSame([], $results['balance_check']['unbalanced_months']);
        $this->assertMoneyEquals('0.00', (string) $results['balance_check']['worst_difference']);
    }

    // ══════════════════════════════════════════════════════════════
    //  SEED-001
    // ══════════════════════════════════════════════════════════════

    /**
     * REGRESSION GUARD — audit finding SEED-001, FIXED 2026-09.
     *
     * Every demo seeder collected its failures, printed them, and
     * then returned SUCCESS — so CI, a deploy script, or anything
     * else reading the exit code saw a clean run while business
     * operations inside had thrown and been swallowed.
     *
     * Source-level, deliberately: running five seeders end to end to
     * assert an exit code would take minutes and prove less. What
     * matters is that each one's completion path is gated on
     * $this->failures.
     */
    public function test_every_demo_seeder_exits_non_zero_when_a_step_failed(): void
    {
        $seeders = glob(base_path('app/Console/Commands/SeedDemo*.php'));

        $this->assertGreaterThanOrEqual(5, count($seeders), 'The demo seeders must be found.');

        $missing = [];

        foreach ($seeders as $path) {
            $source = file_get_contents($path);

            $gated = preg_match(
                '/if \(\$this->failures\) \{\s*return self::FAILURE;/',
                $source
            );

            if (! $gated) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame(
            [], $missing,
            'SEED-001 fix: a run with failed steps must exit non-zero. These still '.
            'return SUCCESS regardless: '.implode(', ', $missing)
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * Buys stock and sells some of it, so the company has a real
     * opening balance sheet — cash, AR, inventory and AP all non-zero.
     * A plan built on an all-zero company cannot detect PLN-002 at all.
     */
    private function tradeSoThatTheCompanyHasARealOpeningPosition(): void
    {
        $product = $this->scenario->products[0];

        $grn = $this->scenario->receiveStock([[$product, 500, 40.00]], '2026-01-05');
        $this->scenario->billGrn($grn, '2026-01-06');

        $so = $this->scenario->createSalesOrder([[$product, 120, 95.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');
        $this->scenario->invoice($so, '2026-02-05');
    }

    private function createPayrollProfile(string $name, string $jobTitle, string $salary)
    {
        $partner = app(\App\Services\PartnerService::class)->create($this->scenario->companyId, [
            'name_en'   => $name,
            'types'     => ['employee'],
            'job_title' => $jobTitle,
        ], $this->actor);

        return app(\App\Services\Accounting\EmployeePayrollProfileService::class)->create(
            $this->scenario->companyId,
            [
                'partner_id'       => $partner->id,
                'base_salary'      => $salary,
                'currency_id'      => $this->scenario->currencyId,
                'expense_category' => 'general_admin',
                'effective_date'   => '2026-01-01',
            ],
            $this->actor
        );
    }
}
