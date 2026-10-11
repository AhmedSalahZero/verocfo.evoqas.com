<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccountingDashboardService
//
//  Accounting > Dashboard — the single-scrolling-page CFO overview
//  Mahmoud asked for (September 2026), built to match the Financial
//  Institutions Dashboard's visual language (KPI cards, section
//  headings, 3D donuts, clickable drill-down tables). This is a
//  READ-ONLY aggregation service, same discipline as
//  CashStatusDashboardService — it writes nothing, and reuses every
//  number from a service that already owns that calculation:
//
//    - Revenue / COGS / Gross Profit / six expense categories / Net
//      Income  → IncomeStatementService::build() (the exact same
//      engine the real P&L report uses — this dashboard can never
//      silently disagree with it)
//    - AR / AP partner tables (Main Currency, functional amounts)
//      → CustomerBalanceService::customersTable() /
//        SupplierBalanceService::suppliersTable() — same tables
//        the Customer/Supplier Balances pages show. Dashboard rows
//        link out to those real pages for invoice-level drill-down
//        (per Mahmoud: "full drill-down now" — rather than a second,
//        parallel invoice-detail implementation living only on the
//        dashboard, this points straight at the one that already
//        exists and is already correct).
//    - Cash & Bank position → LedgerStatementService::totals(), the
//      same ending-balance calculation Trial Balance / Account
//      Statement / CashAvailabilityService all already use.
//    - Inventory value (only if company->hasInventory()) →
//      StockLedgerService::categoryValueStatement()'s per-category
//      "ending" rolled up to a single total.
//
//  Two periods are always computed together, not chosen via a
//  filter round-trip: 'month' (calendar month to date) and 'ytd'
//  (the open fiscal year covering today, to date — falls back to
//  calendar-year-to-date if no fiscal year row covers today, same
//  fallback IncomeStatementController already uses). The Vue page
//  just toggles which period's numbers it displays — no second
//  request needed.
//
//  AR/AP aging buckets are DUE-DATE based (Current / 1-30 / 31-60 /
//  61-90 / 90+ days past due) — the standard aging definition, and
//  deliberately different from CustomerBalanceService's own
//  per-invoice "aging_days" column (invoice-date based, a separate,
//  already-agreed convention for that report — see that service's
//  header). Both are correct; they answer different questions.
//
//  bcmath throughout (Rule 4).
//
//  @layer    Service
//  @depends  IncomeStatementService, CustomerBalanceService,
//            SupplierBalanceService, LedgerStatementService,
//            CurrencyService, StockLedgerService, CustomerInvoice,
//            SupplierBill, ChartOfAccount, FiscalYear, Company
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FiscalYear;
use App\Models\SupplierBill;
use App\Services\CurrencyService;
use App\Services\Inventory\StockLedgerService;
use Carbon\Carbon;

class AccountingDashboardService
{
    private const AGING_BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    public function __construct(
        private IncomeStatementService $incomeStatementService,
        private CustomerBalanceService $customerBalanceService,
        private SupplierBalanceService $supplierBalanceService,
        private LedgerStatementService $ledger,
        private CurrencyService $currencyService,
        private StockLedgerService $stockLedgerService,
    ) {}

    public function build(int $companyId, Company $company): array
    {
        $today = now()->toDateString();
        $periods = $this->periods($companyId);

        return [
            'periods'      => array_column($periods, 'label', 'key'),
            'pl'           => $this->plSnapshot($companyId, $periods),
            'ar'           => $this->receivables($companyId, $today),
            'ap'           => $this->payables($companyId, $today),
            'cash'         => $this->cashPosition($companyId),
            'inventory'    => $company->hasInventory() ? $this->inventoryValue($companyId, $periods) : null,
            'as_of'        => $today,
        ];
    }

    // ── Periods ──────────────────────────────────────────────────

    private function periods(int $companyId): array
    {
        $today = now();

        $monthPeriod = [
            'key'        => 'month',
            'label'      => $today->format('F Y'),
            'start_date' => $today->copy()->startOfMonth()->toDateString(),
            'end_date'   => $today->toDateString(),
        ];

        $fy = FiscalYear::where('company_id', $companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        $ytdStart = $fy?->start_date->toDateString() ?? $today->copy()->startOfYear()->toDateString();

        $ytdPeriod = [
            'key'        => 'ytd',
            'label'      => 'Year to Date',
            'start_date' => $ytdStart,
            'end_date'   => $today->toDateString(),
        ];

        return [$monthPeriod, $ytdPeriod];
    }

    // ── P&L snapshot ─────────────────────────────────────────────

    private function plSnapshot(int $companyId, array $periods): array
    {
        $result = $this->incomeStatementService->build($companyId, $periods);

        $expenseByCategory = [];
        foreach ($result['expense_sections'] as $section) {
            $expenseByCategory[] = [
                'account_type' => $section['account_type'],
                'totals'       => $section['totals'],
            ];
        }

        $margins = [];
        foreach (array_column($periods, 'key') as $key) {
            $revenue = $result['revenue']['totals'][$key] ?? '0.00';
            $sales   = $result['sales_revenue']['totals'][$key] ?? '0.00';
            $gross   = $result['gross_profit'][$key] ?? '0.00';
            $net     = $result['net_income'][$key] ?? '0.00';

            $margins[$key] = [
                // M8 — gross margin is measured on SALES revenue, the same base as Gross Profit.
            'gross_margin_pct' => bccomp($sales, '0', 2) > 0 ? bcmul(bcdiv($gross, $sales, 4), '100', 1) : '0.0',
                'net_margin_pct'   => bccomp($revenue, '0', 2) > 0 ? bcmul(bcdiv($net, $revenue, 4), '100', 1) : '0.0',
            ];
        }

        return [
            'revenue'         => $result['revenue']['totals'],
            'gross_profit'    => $result['gross_profit'],
            'total_expenses'  => $result['total_expenses'],
            'net_income'      => $result['net_income'],
            'expense_sections'=> $expenseByCategory,
            'margins'         => $margins,
        ];
    }

    // ── Receivables ──────────────────────────────────────────────

    private function receivables(int $companyId, string $today): array
    {
        $invoices = CustomerInvoice::where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->get(['amount_outstanding', 'due_date']);

        $customers = $this->customerBalanceService->customersTable($companyId, null, 'all');

        return [
            'total_outstanding' => (string) $invoices->sum('amount_outstanding'),
            'overdue'           => $this->overdueTotal($invoices, $today),
            'aging'             => $this->agingBuckets($invoices, $today),
            'top_partners'      => array_slice($customers, 0, 10),
            'partner_count'     => count($customers),
        ];
    }

    // ── Payables ─────────────────────────────────────────────────

    private function payables(int $companyId, string $today): array
    {
        $bills = SupplierBill::where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->get(['amount_outstanding', 'due_date']);

        $suppliers = $this->supplierBalanceService->suppliersTable($companyId, null, 'all');

        return [
            'total_outstanding' => (string) $bills->sum('amount_outstanding'),
            'overdue'           => $this->overdueTotal($bills, $today),
            'aging'             => $this->agingBuckets($bills, $today),
            'top_partners'      => array_slice($suppliers, 0, 10),
            'partner_count'     => count($suppliers),
        ];
    }

    /** Shared by AR and AP — sum of amount_outstanding where due_date has passed. */
    private function overdueTotal(\Illuminate\Support\Collection $rows, string $today): string
    {
        return (string) $rows->filter(fn ($r) => $r->due_date && $r->due_date->toDateString() < $today)
            ->sum('amount_outstanding');
    }

    /**
     * Standard due-date aging: Current (not yet due) / 1-30 / 31-60 /
     * 61-90 / 90+ days past due. Returns donut-ready [{category, value}].
     */
    private function agingBuckets(\Illuminate\Support\Collection $rows, string $today): array
    {
        $sums = array_fill_keys(self::AGING_BUCKETS, '0.00');
        $todayCarbon = Carbon::parse($today);

        foreach ($rows as $row) {
            if (! $row->due_date || $row->due_date->toDateString() >= $today) {
                $sums['current'] = bcadd($sums['current'], (string) $row->amount_outstanding, 2);
                continue;
            }

            $daysPastDue = $row->due_date->diffInDays($todayCarbon);

            $bucket = match (true) {
                $daysPastDue <= 30  => '1_30',
                $daysPastDue <= 60  => '31_60',
                $daysPastDue <= 90  => '61_90',
                default             => 'over_90',
            };

            $sums[$bucket] = bcadd($sums[$bucket], (string) $row->amount_outstanding, 2);
        }

        $labels = [
            'current' => 'Current',
            '1_30'    => '1-30 Days',
            '31_60'   => '31-60 Days',
            '61_90'   => '61-90 Days',
            'over_90' => '90+ Days',
        ];

        return collect($labels)
            ->map(fn ($label, $key) => ['category' => $label, 'value' => $sums[$key]])
            ->values()
            ->all();
    }

    // ── Cash & Bank ──────────────────────────────────────────────

    /**
     * Total operating cash — bank current accounts + cash safes, in
     * the company's functional currency only. Deliberately scoped
     * (same documented simplification CashStatusDashboardService
     * uses for safes): a company running foreign-currency bank
     * accounts sees those on the Financial Institutions Cash Status
     * dashboard, currency-tabbed — this KPI is the single functional-
     * currency operating-cash figure for a one-page overview.
     */
    private function cashPosition(int $companyId): string
    {
        $functionalCurrencyId = $this->currencyService->functionalCurrencyId($companyId);
        $today = now()->toDateString();
        $total = '0.00';

        $accounts = BankAccount::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('account_type', 'current')
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->where('currency_id', $functionalCurrencyId)
            ->get(['coa_account_id']);

        foreach ($accounts as $account) {
            $total = bcadd($total, $this->ledgerEndingBalance($companyId, $account->coa_account_id, $today), 2);
        }

        $safes = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->get(['id']);

        foreach ($safes as $safe) {
            $total = bcadd($total, $this->ledgerEndingBalance($companyId, $safe->id, $today), 2);
        }

        return $total;
    }

    private function ledgerEndingBalance(int $companyId, int $coaAccountId, string $asOfDate): string
    {
        $totals = $this->ledger->totals($companyId, $coaAccountId, null, $asOfDate, LedgerStatementService::SIGN_ASSET);

        return (string) ($totals['ending_balance'] ?? '0.00');
    }

    // ── Inventory value ──────────────────────────────────────────

    private function inventoryValue(int $companyId, array $periods): string
    {
        $dateTo = $periods[0]['end_date']; // today
        $categories = $this->stockLedgerService->categoryValueStatement($companyId, '2000-01-01', $dateTo);

        $total = '0.00';
        foreach ($categories as $category) {
            $total = bcadd($total, (string) $category['ending'], 2);
        }

        return $total;
    }
}
