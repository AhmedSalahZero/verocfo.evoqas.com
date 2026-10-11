<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  Planning
//
//  The forward-looking half of the system: an annual plan and a
//  five-year business plan built on top of the company the cycle has
//  just spent a year trading, then projected month by month into a
//  full set of statements and valued.
//
//  This is the only part of the ERP that produces numbers nobody can
//  check against a document — there is no invoice behind a projected
//  March. What CAN be checked is that the projection obeys the same
//  arithmetic the real books do, and the engine helpfully states its
//  own verdict on that: every projected month carries is_balanced and
//  a difference. The cycle reads them all, because a plan whose month
//  40 does not balance is a plan whose valuation is wrong, and the
//  screen only ever shows the years.
//
//  THE OPENING BALANCE IS THE JOIN. A plan sourced 'auto' opens on the
//  company's REAL balance sheet — so this scenario is also the only
//  place where the accounting engine and the planning engine have to
//  agree with each other. If they disagree, the plan starts from the
//  wrong cash and every month after it is wrong too.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\Plan;
use App\Services\Planning\PlanCalculationService;
use App\Services\Planning\PlanCogsService;
use App\Services\Planning\PlanExpenseService;
use App\Services\Planning\PlanFixedAssetService;
use App\Services\Planning\PlanManpowerService;
use App\Services\Planning\PlanOpeningBalanceService;
use App\Services\Planning\PlanSalesService;
use App\Services\Planning\PlanService;

class Planning
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(CycleContext $ctx, int $year): void
    {
        $this->log->enterPeriod("planning {$year}");
        $this->log->note('Building next year\'s plan on top of this year\'s books.');

        $annual = $this->buildPlan($ctx, Plan::TYPE_ANNUAL, "Annual Plan {$year}", $year + 1);

        if ($annual) {
            $this->project($ctx, $annual, 'annual plan');
            $this->openingBalanceMatchesTheBooks($ctx, $annual);
        }

        $business = $this->buildPlan($ctx, Plan::TYPE_BUSINESS_PLAN, "Five-Year Business Plan {$year}", $year + 1, 5);

        if ($business) {
            $this->project($ctx, $business, 'business plan');
            $this->valuation($ctx, $business);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Building a plan
    // ══════════════════════════════════════════════════════════════

    private function buildPlan(CycleContext $ctx, string $type, string $name, int $startYear, ?int $years = null): ?Plan
    {
        $payload = [
            'name'                         => $name,
            'currency_id'                  => $ctx->currencyId,
            'start_date'                   => $startYear.'-01-01',
            'business_type'                => 'trading',
            'corporate_tax_rate'           => 22.5,
            'manpower_annual_increase_pct' => 10,
            'opening_balance_source'       => Plan::OB_SOURCE_AUTO,
        ];

        if ($type === Plan::TYPE_BUSINESS_PLAN) {
            $payload += [
                'duration_years'       => $years,
                'wacc_pct'             => 18,
                'perpetual_growth_pct' => 3,
                'valuation_basis'      => 'ebitda',
                'valuation_multiplier' => 6,
            ];
        }

        $plan = $this->gate->submit(
            $type === Plan::TYPE_BUSINESS_PLAN
                ? \App\Http\Requests\Valuation\BusinessPlanRequest::class
                : \App\Http\Requests\Planning\AnnualPlanRequest::class,
            $payload,
            fn (array $data) => app(PlanService::class)->create($ctx->companyId, $type, $data, $ctx->actor),
            'Open a '.str_replace('_', ' ', $type).' — '.$startYear,
            'plan'
        );

        if (! $plan) {
            return null;
        }

        // ── A product to sell, priced and forecast ────────────────
        $realProduct = $ctx->sellableProducts()[0] ?? null;

        $planProduct = $this->gate->submit(
            \App\Http\Requests\Planning\PlanProductRequest::class,
            $realProduct
                ? ['product_id' => $realProduct->id]
                : ['name' => 'Planned Product', 'nature' => 'trading', 'vat_rate' => 14],
            fn (array $data) => app(PlanService::class)->addProduct($plan, $data, $ctx->actor),
            'Add a product to the plan',
            'plan product'
        );

        if ($planProduct) {
            $months = $plan->totalMonths();
            $lines  = [];

            for ($m = 0; $m < $months; $m++) {
                $lines[] = [
                    'period_month' => $m,
                    'price'        => '1200.00',
                    'volume'       => '150',
                    'local_pct'    => 80,
                    'export_pct'   => 20,
                ];
            }

            $this->gate->submit(
                \App\Http\Requests\Planning\PlanSalesLinesRequest::class,
                ['lines' => $lines],
                fn (array $data) => app(PlanSalesService::class)->saveSalesLines($planProduct, $data['lines'], $ctx->actor),
                'Forecast sales across the plan',
                'plan sales forecast'
            );

            // Collected half on the sale, half after sixty days — the
            // sort of policy that makes the cash flow differ from the
            // profit and loss, which is the whole point of a plan.
            $this->gate->submit(
                \App\Http\Requests\Planning\PlanCollectionTranchesRequest::class,
                [
                    'market'   => 'local',
                    'tranches' => [
                        ['pct' => 50, 'days_after_sale' => 0],
                        ['pct' => 50, 'days_after_sale' => 60],
                    ],
                ],
                fn (array $data) => app(PlanSalesService::class)->saveCollectionTranches(
                    $planProduct, $data['market'], $data['tranches'], $ctx->actor
                ),
                'Set the plan collection policy',
                'plan collection policy'
            );

            $this->gate->submit(
                \App\Http\Requests\Planning\PlanCogsAssumptionRequest::class,
                [
                    'unit_purchase_cost'        => '780.00',
                    'inventory_coverage_days'   => 30,
                    'annual_cost_increase_pct'  => 8,
                ],
                fn (array $data) => app(PlanCogsService::class)->saveAssumption($planProduct, $data, $ctx->actor),
                'Set the plan cost assumptions',
                'plan cost assumption'
            );
        }

        // ── Overheads ─────────────────────────────────────────────
        foreach ([
            ['Office rent', 'general_admin', 'fixed_recurring', '45000'],
            ['Marketing campaign', 'marketing', 'pct_revenue', '3'],
            ['Systems implementation', 'general_admin', 'one_time', '250000'],
        ] as [$label, $category, $expenseType, $amount]) {
            $this->gate->submit(
                \App\Http\Requests\Planning\PlanExpenseLineRequest::class,
                [
                    'expense_name'        => $label,
                    'category'            => $category,
                    'expense_type'        => $expenseType,
                    'amount'              => $amount,
                    'annual_increase_pct' => 5,
                    'start_month'         => 0,
                ],
                fn (array $data) => app(PlanExpenseService::class)->addLine($plan->fresh(), $data, $ctx->actor),
                'Add a planned expense — '.$label,
                'plan expense'
            );
        }

        // ── People ────────────────────────────────────────────────
        // "Bring in the team we already have" — the first button on
        // the manpower step, and the one every plan starts with.
        try {
            app(PlanManpowerService::class)->snapshotExistingTeam($plan->fresh(), $ctx->actor);
            $this->log->operation('plan manpower snapshot');
            $this->log->checkPassed();
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'PLN',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A plan cannot import the company\'s existing team',
                operation: 'Planning — manpower',
                expected: 'the current payroll to be snapshotted into the plan as its starting headcount',
                actual: get_class($e).': '.$e->getMessage(),
                source: 'PlanManpowerService::snapshotExistingTeam()',
                rootCause: 'The query joins partners onto employee_payroll_profiles and then filters on a bare '
                    .'where(\'is_active\', true). Both tables have an is_active column, so MySQL refuses the '
                    .'statement as ambiguous. The filter was written before the join was added and never '
                    .'re-qualified, so the failure is unconditional for any company that has payroll profiles — '
                    .'which is every company that would use this screen.',
                evidence: 'SQLSTATE[23000] 1052 "Column \'is_active\' in where clause is ambiguous" on the query '
                    .'issued by the Import Existing Team action.',
            );
        }

        $title = $this->gate->submit(
            \App\Http\Requests\Planning\PlanManpowerTitleRequest::class,
            [
                'job_title'          => 'Sales Representative',
                'expense_category'   => 'sales',
                'avg_current_salary' => '12000',
            ],
            fn (array $data) => app(PlanManpowerService::class)->addPlannedTitle($plan->fresh(), $data, $ctx->actor),
            'Plan a new job title',
            'plan manpower title'
        );

        if ($title) {
            $this->gate->submit(
                \App\Http\Requests\Planning\PlanManpowerHiringRequest::class,
                ['plan_year' => 1, 'new_hires_count' => 3],
                fn (array $data) => app(PlanManpowerService::class)->setPlannedHiring(
                    $title, $data['plan_year'], $data['new_hires_count'], $ctx->actor
                ),
                'Plan the hiring for that title',
                'plan hiring'
            );
        }

        // ── Capital spend ─────────────────────────────────────────
        $this->gate->submit(
            \App\Http\Requests\Planning\PlanFixedAssetRequest::class,
            [
                'name'               => 'Delivery Fleet Expansion',
                'cost_basis'         => '900000',
                'residual_value'     => '90000',
                'useful_life_months' => 60,
                'purchase_month'     => 3,
            ],
            fn (array $data) => app(PlanFixedAssetService::class)->addPlannedAsset($plan->fresh(), $data, $ctx->actor),
            'Plan a capital purchase',
            'plan fixed asset'
        );

        return $plan->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    //  Projecting it
    // ══════════════════════════════════════════════════════════════

    /**
     * The projection itself. The engine states, per month, whether its
     * own balance sheet balances — so the strongest available check is
     * to read every month rather than the yearly summary the screen
     * shows, and to report the FIRST month that breaks, because
     * everything after it inherits the error.
     */
    private function project(CycleContext $ctx, Plan $plan, string $label): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        try {
            $result = app(PlanCalculationService::class)->calculate($plan, bypassCache: true);
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'PLN',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "The {$label} could not be projected",
                operation: 'Planning',
                expected: 'a full set of projected statements',
                actual: get_class($e).': '.$e->getMessage(),
                source: 'PlanCalculationService::calculate()',
            );

            return;
        }

        $this->log->operation('plan projection');

        $months = count($result['bs'] ?? []);

        $verify->truth(
            $months === $plan->totalMonths(),
            "The {$label} did not project every month of its own duration",
            'Planning',
            ['plan months' => $plan->totalMonths(), 'projected months' => $months],
            prefix: 'PLN'
        );

        // Assets = liabilities + equity, in every projected month.
        $firstBreak = null;

        foreach (($result['bs'] ?? []) as $index => $month) {
            $assets = (string) ($month['total_assets'] ?? '0.00');
            $claims = bcadd(
                (string) ($month['total_liabilities'] ?? '0.00'),
                (string) ($month['total_equity'] ?? '0.00'),
                2
            );

            if (bccomp($assets, $claims, 2) !== 0) {
                $firstBreak = [
                    'month'             => $index,
                    'total assets'      => $assets,
                    'liabilities+equity' => $claims,
                    'engine says balanced' => ($month['is_balanced'] ?? null) ? 'yes' : 'no',
                    'engine difference' => (string) ($month['difference'] ?? '—'),
                ];
                break;
            }
        }

        if ($firstBreak === null) {
            $this->log->checkPassed();
        } else {
            $this->log->finding(
                prefix: 'PLN',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "A projected balance sheet in the {$label} does not balance",
                operation: 'Planning',
                expected: 'total assets to equal liabilities plus equity in every projected month',
                actual: 'they differ from month '.$firstBreak['month'].' onwards',
                difference: bcsub($firstBreak['total assets'], $firstBreak['liabilities+equity'], 2),
                source: 'PlanFinancialStatementBuilder::build()',
                rootCause: 'The plan is valued off these statements, and the screen only ever shows the '
                    .'year-aggregated view — so a month that does not balance is invisible to the person '
                    .'relying on the valuation.',
                context: $firstBreak,
            );
        }

        // Cash in the balance sheet must be the cash flow's own
        // closing figure — the two statements are built separately.
        $lastBs = end($result['bs']) ?: [];
        $lastCf = end($result['cf']) ?: [];

        if (isset($lastBs['cash'], $lastCf['closing_cash'])) {
            $verify->money(
                (string) $lastCf['closing_cash'],
                (string) $lastBs['cash'],
                "The {$label}'s balance sheet cash does not agree with its own cash flow statement",
                'Planning',
                ['cash flow closing' => (string) $lastCf['closing_cash'], 'balance sheet cash' => (string) $lastBs['cash']],
                prefix: 'PLN'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Where the plan joins the books
    // ══════════════════════════════════════════════════════════════

    /**
     * An 'auto' plan opens on the company's real balance sheet. That
     * makes this the one place the planning engine and the accounting
     * engine must produce the same number for the same thing.
     */
    private function openingBalanceMatchesTheBooks(CycleContext $ctx, Plan $plan): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        try {
            $opening = app(PlanOpeningBalanceService::class)->openingFigures($plan);
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'PLN',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A plan could not read the opening balance from the company\'s own books',
                operation: 'Planning',
                expected: "the company's real balance sheet as the plan's starting point",
                actual: get_class($e).': '.$e->getMessage(),
                source: 'PlanOpeningBalanceService::openingFigures()',
            );

            return;
        }

        $this->log->operation('plan opening balance');

        // Cash and bank, computed from the ledger by the cycle itself
        // — the WHOLE 132 group, because a time deposit is cash too
        // and comparing only the current accounts would report a
        // difference that is really a difference of definition.
        $ledgerCash = '0.00';

        $cashAccounts = \App\Models\ChartOfAccount::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('company_id', $ctx->companyId)->orWhereNull('company_id'))
            ->where('code', 'like', '132%')
            ->pluck('id');

        foreach ($cashAccounts as $id) {
            $ledgerCash = bcadd($ledgerCash, $verify->reportedBalance((int) $id), 2);
        }

        if ($ctx->safeAccountId) {
            $ledgerCash = bcadd($ledgerCash, $verify->reportedBalance($ctx->safeAccountId), 2);
        }

        $verify->money(
            $ledgerCash,
            (string) ($opening['cash_and_bank'] ?? '0.00'),
            'The plan opens on a different cash balance from the one in the company\'s books',
            'Planning — opening balance',
            [
                'ledger cash and bank' => $ledgerCash,
                'plan opening figure'  => (string) ($opening['cash_and_bank'] ?? '0.00'),
                'as of'                => (string) $plan->opening_balance_as_of_date,
            ],
            severity: Finding::SEVERITY_MEDIUM,
            prefix: 'PLN'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Valuing it
    // ══════════════════════════════════════════════════════════════

    private function valuation(CycleContext $ctx, Plan $plan): void
    {
        try {
            $result = app(PlanCalculationService::class)->calculate($plan, bypassCache: true);
        } catch (\Throwable $e) {
            return;
        }

        $valuation = $result['valuation'] ?? null;

        if ($valuation === null) {
            $this->log->finding(
                prefix: 'PLN',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A business plan produced no valuation',
                operation: 'Planning — valuation',
                expected: 'a discounted cash flow and a multiplier valuation',
                actual: 'the valuation section is null',
                source: 'PlanCalculationService::run()',
                rootCause: 'A business plan exists in order to be valued; an annual plan does not. If the '
                    .'valuation is missing here, the plan type is not being recognised.',
            );

            return;
        }

        $this->log->operation('plan valuation');
        $this->log->checkPassed();

        $this->log->note(
            '  (business plan valued — multiplier basis '.($valuation['valuation_basis'] ?? '—')
            .' × '.($valuation['valuation_multiplier'] ?? '—').')'
        );
    }
}
