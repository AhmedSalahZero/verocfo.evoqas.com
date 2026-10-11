<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  HrDashboardService
//
//  HR > Dashboard (September 2026, per Mahmoud — "same pattern" as
//  the Accounting and Sales Orders dashboards). Same discipline:
//  READ-ONLY aggregation, reusing the two already-built pure-read
//  balance services (AdvancesSettlementsService, GovernmentRemittance
//  Service) rather than duplicating their balance math, and querying
//  PayrollRun/PayrollRunLine/EosbProvisionRunLine directly for
//  payroll-cost activity the same direct-query style
//  AccountingDashboardService and SalesOrderDashboardService use.
//
//  CURRENCY: unlike SalesOrder (which carries its own exchange_rate
//  per row for genuine multi-currency contracts), PayrollRunLine has
//  a currency_id but no exchange_rate of its own — payroll in this
//  system is built on the assumption employees are paid in the
//  company's functional currency (the normal case for an Egypt/MENA
//  SME). Every figure here is taken at face value, no conversion.
//
//  PERIOD SCOPING — same lesson as SalesOrderDashboardService's
//  September 2026 fix, applied correctly from the start here:
//  ACTIVITY (payroll cost, gross/net pay, top earners this period)
//  is scoped to the selected period (This Month / Year to Date, by
//  the PayrollRun's own period_year/period_month — payroll is
//  naturally bucketed by month already, so no separate date-range
//  logic is needed the way Sales Orders needed issuance_date). BALANCES
//  (EOSB provision liability, tax/social-insurance remittance,
//  outstanding employee advances & custody) are point-in-time, like
//  Accounting Dashboard's AR/AP — they do NOT move with the period
//  toggle, and are shown in their own clearly-labeled "as of today"
//  section so that stays unambiguous rather than looking broken.
//
//  Only POSTED payroll runs and EOSB provision runs count anywhere
//  in this service — a draft run hasn't actually happened yet.
//
//  Top Employees / Outstanding Advances tables link straight back to
//  the real Employees / Advances & Settlements pages rather than
//  re-implementing their own detail a second time — same
//  "full drill-down via the real page" convention as the other two
//  dashboards.
//
//  bcmath throughout (Rule 4).
//
//  @layer    Service
//  @depends  PayrollRun, PayrollRunLine, EmployeePayrollProfile,
//            EosbProvisionRunLine, AdvancesSettlementsService,
//            GovernmentRemittanceService
// ══════════════════════════════════════════════════════════════════

use App\Models\EmployeePayrollProfile;
use App\Models\EosbProvisionRun;
use App\Models\EosbProvisionRunLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use Illuminate\Support\Collection;

class HrDashboardService
{
    private const CATEGORY_ORDER = [
        EmployeePayrollProfile::CATEGORY_MANUFACTURING,
        EmployeePayrollProfile::CATEGORY_SALES,
        EmployeePayrollProfile::CATEGORY_MARKETING,
        EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN,
        EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE,
    ];

    public function __construct(
        private AdvancesSettlementsService $advancesService,
        private GovernmentRemittanceService $remittanceService,
    ) {}

    public function build(int $companyId): array
    {
        $periods = $this->periods();

        $data = [];
        foreach ($periods as $period) {
            $data[$period['key']] = $this->buildForPeriod($companyId, $period);
        }

        return [
            'periods'   => array_column($periods, 'label', 'key'),
            'data'      => $data,
            'headcount' => $this->activeHeadcount($companyId),
            'balances'  => $this->balances($companyId),
            'as_of'     => now()->toDateString(),
        ];
    }

    // ── Periods ──────────────────────────────────────────────────

    /**
     * Payroll is naturally bucketed by calendar month already
     * (PayrollRun.period_year/period_month) — 'month' is exactly
     * this year+month, 'ytd' is every month from January through
     * this one, same calendar year.
     */
    private function periods(): array
    {
        $today = now();

        return [
            [
                'key'   => 'month',
                'label' => $today->format('F Y'),
                'year'  => $today->year,
                'months'=> [$today->month],
            ],
            [
                'key'   => 'ytd',
                'label' => 'Year to Date',
                'year'  => $today->year,
                'months'=> range(1, $today->month),
            ],
        ];
    }

    /** Every activity section, scoped to posted payroll runs whose period falls inside this one period. */
    private function buildForPeriod(int $companyId, array $period): array
    {
        $lines = PayrollRunLine::where('company_id', $companyId)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED)
                ->where('period_year', $period['year'])
                ->whereIn('period_month', $period['months']))
            ->with('partner:id,code,name_en,name_ar')
            ->get();

        return [
            'overview'       => $this->overview($lines),
            'category_mix'   => $this->categoryMix($lines),
            'top_employees'  => $this->topEmployees($lines),
        ];
    }

    // ── Overview ─────────────────────────────────────────────────

    private function overview(Collection $lines): array
    {
        $grossPay = (string) $lines->sum('gross_pay');
        $netPay = (string) $lines->sum('net_pay');
        $employerSocialInsurance = (string) $lines->sum('social_insurance_employer_amount');
        $employeeDeductions = (string) $lines->sum(fn ($l) => bcadd(
            (string) $l->tax_amount,
            bcadd((string) $l->social_insurance_employee_amount, (string) $l->advance_deduction_amount, 2),
            2
        ));

        return [
            'employee_count'          => $lines->pluck('partner_id')->unique()->count(),
            'gross_pay'               => $grossPay,
            'net_pay'                 => $netPay,
            'total_cost'              => bcadd($grossPay, $employerSocialInsurance, 2),
            'employee_deductions'     => $employeeDeductions,
            'employer_social_insurance' => $employerSocialInsurance,
        ];
    }

    // ── Category mix (mirrors Accounting Dashboard's six-category donut) ──

    private function categoryMix(Collection $lines): array
    {
        $sums = array_fill_keys(self::CATEGORY_ORDER, '0.00');

        foreach ($lines as $line) {
            $category = in_array($line->expense_category, self::CATEGORY_ORDER, true) ? $line->expense_category : EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN;
            $sums[$category] = bcadd($sums[$category], (string) $line->gross_pay, 2);
        }

        $labels = [
            EmployeePayrollProfile::CATEGORY_MANUFACTURING   => 'Manufacturing',
            EmployeePayrollProfile::CATEGORY_SALES           => 'Sales',
            EmployeePayrollProfile::CATEGORY_MARKETING       => 'Marketing',
            EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN   => 'General & Administrative',
            EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE => 'Cost of Service',
        ];

        return collect(self::CATEGORY_ORDER)
            ->map(fn ($category) => ['category' => $labels[$category], 'value' => $sums[$category]])
            ->filter(fn ($row) => bccomp($row['value'], '0', 2) > 0)
            ->values()
            ->all();
    }

    // ── Top Employees by gross pay ─────────────────────────────────

    private function topEmployees(Collection $lines): array
    {
        $byEmployee = [];

        foreach ($lines as $line) {
            $partnerId = $line->partner_id;
            if (! isset($byEmployee[$partnerId])) {
                $byEmployee[$partnerId] = [
                    'partner_id'                  => $partnerId,
                    'employee_payroll_profile_id' => $line->employee_payroll_profile_id,
                    'name'                        => $line->partner?->name_en,
                    'code'                        => $line->partner?->code,
                    'gross_pay'                   => '0.00',
                    'net_pay'                     => '0.00',
                ];
            }

            $byEmployee[$partnerId]['gross_pay'] = bcadd($byEmployee[$partnerId]['gross_pay'], (string) $line->gross_pay, 2);
            $byEmployee[$partnerId]['net_pay'] = bcadd($byEmployee[$partnerId]['net_pay'], (string) $line->net_pay, 2);
        }

        usort($byEmployee, fn ($a, $b) => bccomp($b['gross_pay'], $a['gross_pay'], 2));

        return array_slice(array_values($byEmployee), 0, 10);
    }

    // ── Headcount (as of today, not period-scoped) ─────────────────

    private function activeHeadcount(int $companyId): array
    {
        $active = EmployeePayrollProfile::where('company_id', $companyId)->where('is_active', true)->count();
        $total = EmployeePayrollProfile::where('company_id', $companyId)->count();

        return ['active' => $active, 'total' => $total];
    }

    // ── Balances (as of today, not period-scoped) ──────────────────

    private function balances(int $companyId): array
    {
        $remittance = $this->remittanceService->balances($companyId);

        $eosbBalance = (string) EosbProvisionRunLine::where('company_id', $companyId)
            ->whereHas('eosbProvisionRun', fn ($q) => $q->where('status', EosbProvisionRun::STATUS_POSTED))
            ->sum('provision_amount');

        $advances = $this->advancesService->paginate($companyId, ['page' => 1]);
        $advancesTotal = '0.00';
        $topAdvances = [];
        foreach ($advances->items() as $row) {
            $advancesTotal = bcadd($advancesTotal, bcadd($row['advance_balance'], $row['custody_balance'], 2), 2);
            $topAdvances[] = [
                'partner_id' => $row['partner_id'],
                'name'       => $row['partner']?->name_en,
                'code'       => $row['partner']?->code,
                'advance'    => $row['advance_balance'],
                'custody'    => $row['custody_balance'],
                'total'      => bcadd($row['advance_balance'], $row['custody_balance'], 2),
            ];
        }

        return [
            'eosb_provision'      => $eosbBalance,
            'tax_remittance_due'  => $remittance['tax'],
            'social_insurance_due'=> $remittance['social_insurance'],
            'advances_outstanding'=> $advancesTotal,
            'top_advances'        => $topAdvances,
        ];
    }
}
