<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  Reporting
//
//  Everything a finance team READS. Up to this point the cycle has
//  only tested what the ERP writes; a company that posts perfectly
//  and reports wrongly is still a company whose accountant signs the
//  wrong number.
//
//  TWO LEVELS OF TESTING, and they are not the same thing:
//
//    RUNS      — the report was produced at all, against a company
//                with a full year of real history. Half of these
//                services had never been executed against non-trivial
//                data before this cycle existed; several of them
//                divide, parse dates, or index into arrays that are
//                empty in a fresh company and populated in a real one.
//
//    RECONCILES — the report AGREES with the general ledger. This is
//                the level that matters. A receivables report that
//                runs cleanly and disagrees with account 133 by
//                200,000 is worse than one that crashes, because
//                nobody notices.
//
//  Every reconciliation below is stated as an accounting identity, so
//  a failure names the identity that broke rather than "numbers
//  differ". Reports that have no such identity (analytics breakdowns,
//  dashboards, projections) are executed and shape-checked only, and
//  the register says so rather than implying more.
//
//  NOTHING HERE WRITES. Every service called is a read path.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\Verify;
use App\Models\CustomerInvoice;
use App\Models\SupplierBill;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\Accounting\CashFlowStatementService;
use App\Services\Accounting\CustomerBalanceService;
use App\Services\Accounting\HrDashboardService;
use App\Services\Accounting\LedgerStatementService;
use App\Services\Accounting\SupplierBalanceService;
use App\Services\Analytics\ExpenseAnalysisService;
use App\Services\Analytics\SalesAnalysisService;
use App\Services\ContractDashboardService;
use App\Services\FinancialInstitutions\CashFlowProjectionService;
use App\Services\FinancialInstitutions\CashStatusDashboardService;
use App\Services\FinancialInstitutions\ChequeAgingService;
use App\Services\FinancialInstitutions\InvoiceAgingService;
use App\Services\FinancialInstitutions\LcStatementService;
use App\Services\FinancialInstitutions\LgLcStatusDashboardService;
use App\Services\FinancialInstitutions\LgStatementService;
use App\Services\FinancialInstitutions\WithdrawalStatementService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Projects\SalesOrderDashboardService;

class Reporting
{
    public function __construct(
        private CycleLog $log,
    ) {
    }

    public function run(CycleContext $ctx, int $firstYear, int $lastYear): void
    {
        $this->log->enterPeriod('reporting');
        $this->log->note('Reading everything a finance team reads.');

        $verify = new Verify($this->log, $ctx->companyId);
        $from   = "{$firstYear}-01-01";
        $to     = "{$lastYear}-12-31";

        // Order matters. If the reports are not filtered by company
        // at all, every total below is the sum of two companies and
        // reporting the same root cause three more times as separate
        // "the report does not add up" findings would bury it.
        $scoped = $this->reportsAreCompanyScoped($ctx, $verify);

        $this->receivablesReconcile($ctx, $verify, $to, $scoped);
        $this->payablesReconcile($ctx, $verify, $to, $scoped);
        $this->ledgerStatementReconciles($ctx, $verify, $from, $to);
        $this->agingReconciles($ctx, $verify, $to);
        $this->cashPositionReconciles($ctx, $verify, $to);
        $this->inventoryStatementReconciles($ctx, $verify, $from, $to);
        $this->reportsRun($ctx, $from, $to);
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 1 — receivables report == GL Accounts Receivable
    // ══════════════════════════════════════════════════════════════

    /**
     * The customer balances screen is where a credit controller reads
     * "who owes us what". Its total is the same number the balance
     * sheet calls Accounts Receivable, or one of the two is wrong.
     */
    private function receivablesReconcile(CycleContext $ctx, Verify $verify, string $asOf, bool $scoped): void
    {
        $service = app(CustomerBalanceService::class);

        try {
            $rows = $service->customersTable($ctx->companyId, null);
        } catch (\Throwable $e) {
            $this->reportFailed('The customer balances report', $e, 'CustomerBalanceService::customersTable()');

            return;
        }

        $reported = '0.00';
        foreach ($rows as $row) {
            $reported = bcadd($reported, (string) $row['net_balance'], 2);
        }

        // The subledger's own total, computed independently of the
        // report — straight off the invoices, this company only.
        $subledger = (string) (CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->sum('amount_outstanding') ?: '0.00');

        if ($scoped) {
            $verify->money(
                $subledger,
                $reported,
                'The customer balances report does not add up to the open invoices behind it',
                'Receivables reporting',
                ['posted open invoices' => $subledger, 'report total' => $reported],
                prefix: 'RPT'
            );
        }

        // And the subledger against the general ledger. Trade
        // receivables sit in 133; the report is the subsidiary of that
        // control account, so a difference is unreconciled AR.
        // The control account is the 133 GROUP, not the 133001 leaf —
        // accrued revenue, retentions and second AR accounts all live
        // under it, and comparing one leaf would report a difference
        // that is really a difference of scope.
        $glAr = $this->groupBalance($ctx, '133');

        if (bccomp($glAr, '0', 2) !== 0) {
            $verify->money(
                $glAr,
                $subledger,
                'The receivables subledger does not reconcile to the Accounts Receivable control account',
                'Receivables reporting',
                array_merge(
                    [
                        'GL 133 Receivables (all leaves)' => $glAr,
                        'sum of open invoices'            => $subledger,
                        'as of'                           => $asOf,
                    ],
                    // What actually moved the control account. An
                    // unreconciled AR balance is only actionable once
                    // you know WHICH kind of document moved the GL
                    // without moving the invoice behind it — so the
                    // finding names them instead of just the gap.
                    $this->movementBySourceType($ctx, '133'),
                    $this->attribution($ctx, '133', bcsub($glAr, $subledger, 2))
                ),
                severity: Finding::SEVERITY_HIGH,
                prefix: 'RPT'
            );
        }

        $this->log->operation('receivables report');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 2 — payables report == GL Accounts Payable
    // ══════════════════════════════════════════════════════════════

    private function payablesReconcile(CycleContext $ctx, Verify $verify, string $asOf, bool $scoped): void
    {
        $service = app(SupplierBalanceService::class);

        try {
            $rows = $service->suppliersTable($ctx->companyId, null);
        } catch (\Throwable $e) {
            $this->reportFailed('The supplier balances report', $e, 'SupplierBalanceService::suppliersTable()');

            return;
        }

        $reported = '0.00';
        foreach ($rows as $row) {
            $reported = bcadd($reported, (string) $row['net_balance'], 2);
        }

        $subledger = (string) (SupplierBill::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->sum('amount_outstanding') ?: '0.00');

        if ($scoped) {
            $verify->money(
                $subledger,
                $reported,
                'The supplier balances report does not add up to the open bills behind it',
                'Payables reporting',
                ['posted open bills' => $subledger, 'report total' => $reported],
                prefix: 'RPT'
            );
        }

        $glAp = $this->groupBalance($ctx, '221');

        if (bccomp($glAp, '0', 2) !== 0) {
            // 221 is a liability, so its true balance is negative in
            // debit-positive terms. Compare magnitudes.
            $verify->money(
                $this->magnitude($glAp),
                $subledger,
                'The payables subledger does not reconcile to the Accounts Payable control account',
                'Payables reporting',
                array_merge(
                    [
                        'GL 221 Payables (all leaves)' => $glAp,
                        'sum of open bills'            => $subledger,
                        'as of'                        => $asOf,
                    ],
                    $this->movementBySourceType($ctx, '221'),
                    $this->attribution($ctx, '221', bcsub($this->magnitude($glAp), $subledger, 2))
                ),
                prefix: 'RPT'
            );
        }

        $this->log->operation('payables report');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 3 — account statement == the ledger it reads from
    // ══════════════════════════════════════════════════════════════

    /**
     * The account statement is the screen an accountant opens to
     * explain a balance. Its closing figure must equal the account's
     * own posted balance, and its own arithmetic must close:
     *
     *     opening + debits − credits  ==  closing
     *
     * Both are checked, because a statement can land on the right
     * closing balance from the wrong opening figure and nobody sees it.
     */
    private function ledgerStatementReconciles(CycleContext $ctx, Verify $verify, string $from, string $to): void
    {
        $service = app(LedgerStatementService::class);

        $accounts = [
            ['133001', $verify->accountId('133001'), LedgerStatementService::SIGN_ASSET],
            ['221001', $verify->accountId('221001'), LedgerStatementService::SIGN_LIABILITY],
            ['bank', (int) ($ctx->bankAccounts[0]->coa_account_id ?? 0), LedgerStatementService::SIGN_ASSET],
        ];

        foreach ($accounts as [$code, $accountId, $sign]) {
            if (! $accountId) {
                continue;
            }

            try {
                $totals = $service->totals($ctx->companyId, $accountId, $from, $to, $sign);
            } catch (\Throwable $e) {
                $this->reportFailed("The account statement for {$code}", $e, 'LedgerStatementService::totals()');

                continue;
            }

            // (a) the statement's own arithmetic closes.
            $movement = $sign === LedgerStatementService::SIGN_LIABILITY
                ? bcsub($totals['total_credit'], $totals['total_debit'], 2)
                : bcsub($totals['total_debit'], $totals['total_credit'], 2);

            $verify->money(
                bcadd($totals['beginning_balance'], $movement, 2),
                $totals['ending_balance'],
                "The account statement for {$code} does not add up: opening plus movement is not the closing balance",
                'Account statement reporting',
                [
                    'opening'  => $totals['beginning_balance'],
                    'debits'   => $totals['total_debit'],
                    'credits'  => $totals['total_credit'],
                    'closing'  => $totals['ending_balance'],
                ],
                prefix: 'RPT'
            );

            // (b) the statement agrees with the ledger itself. The
            // cycle computes the same figure with its own SQL, so the
            // two are genuinely independent.
            $ledger = $verify->reportedBalance($accountId);
            $expected = $sign === LedgerStatementService::SIGN_LIABILITY
                ? $this->magnitude($ledger)
                : $ledger;

            $verify->money(
                $expected,
                $totals['ending_balance'],
                "The account statement for {$code} disagrees with the general ledger",
                'Account statement reporting',
                ['ledger' => $ledger, 'statement' => $totals['ending_balance'], 'sign convention' => $sign],
                prefix: 'RPT'
            );

            $this->log->operation('account statement');
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 4 — aging buckets == total outstanding
    // ══════════════════════════════════════════════════════════════

    /**
     * An aging report slices the same money into buckets. The slices
     * must add back to the whole, or an invoice has fallen between
     * two buckets and is invisible to whoever chases it.
     */
    private function agingReconciles(CycleContext $ctx, Verify $verify, string $asOf): void
    {
        $currencies = app(CashStatusDashboardService::class)->currencies($ctx->companyId);

        try {
            $aging = app(InvoiceAgingService::class)->build($ctx->companyId, $asOf, $currencies);
        } catch (\Throwable $e) {
            $this->reportFailed('The invoice aging report', $e, 'InvoiceAgingService::build()');

            return;
        }

        foreach ([
            'customer' => CustomerInvoice::class,
            'supplier' => SupplierBill::class,
        ] as $side => $model) {
            $bucketed = '0.00';

            foreach ($currencies as $code) {
                foreach (($aging[$side][$code]['totals'] ?? []) as $dueType => $buckets) {
                    foreach ($buckets as $amount) {
                        $bucketed = bcadd($bucketed, (string) $amount, 2);
                    }
                }
            }

            // The aging report only ages documents that HAVE a due
            // date, so the comparison must use the same population —
            // otherwise this test reports a defect that is really a
            // difference of definition.
            $outstanding = (string) ($model::withoutGlobalScopes()
                ->where('company_id', $ctx->companyId)
                ->where('amount_outstanding', '>', 0)
                ->whereNotNull('due_date')
                ->sum('amount_outstanding') ?: '0.00');

            $verify->money(
                $outstanding,
                $bucketed,
                "The {$side} aging buckets do not add up to the total outstanding",
                'Aging reporting',
                ['total outstanding' => $outstanding, 'sum of buckets' => $bucketed, 'as of' => $asOf],
                prefix: 'RPT'
            );
        }

        try {
            app(ChequeAgingService::class)->build($ctx->companyId, $asOf, $currencies);
            $this->log->operation('cheque aging report');
        } catch (\Throwable $e) {
            $this->reportFailed('The cheque aging report', $e, 'ChequeAgingService::build()');
        }

        $this->log->operation('invoice aging report');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 5 — cash dashboard == the bank accounts' own ledgers
    // ══════════════════════════════════════════════════════════════

    private function cashPositionReconciles(CycleContext $ctx, Verify $verify, string $asOf): void
    {
        try {
            $status = app(CashStatusDashboardService::class)->build($ctx->companyId, $asOf);
        } catch (\Throwable $e) {
            $this->reportFailed('The cash status dashboard', $e, 'CashStatusDashboardService::build()');

            return;
        }

        // The functional-currency cash line against the sum of this
        // company's own bank ledger accounts.
        $ledgerCash = '0.00';
        foreach ($ctx->bankAccounts as $account) {
            $ledgerCash = bcadd($ledgerCash, $verify->reportedBalance((int) $account->coa_account_id), 2);
        }

        $reported = (string) ($status['reports']['cash_and_banks']['EGP'] ?? '0.00');

        $verify->money(
            $ledgerCash,
            $reported,
            'The cash dashboard disagrees with the bank accounts\' own ledger balances',
            'Cash position reporting',
            [
                'sum of bank ledger accounts' => $ledgerCash,
                'dashboard figure'            => $reported,
                'as of'                       => $asOf,
            ],
            severity: Finding::SEVERITY_MEDIUM,
            prefix: 'RPT'
        );

        $this->log->operation('cash status dashboard');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity 6 — inventory value statement == stock balances
    // ══════════════════════════════════════════════════════════════

    private function inventoryStatementReconciles(CycleContext $ctx, Verify $verify, string $from, string $to): void
    {
        try {
            $statement = app(StockLedgerService::class)->categoryValueStatement($ctx->companyId, $from, $to);
        } catch (\Throwable $e) {
            $this->reportFailed('The inventory value statement', $e, 'StockLedgerService::categoryValueStatement()');

            return;
        }

        // Main-category rows already roll their sub-categories up, so
        // only the top-level rows are summed — adding the children too
        // would double every product that sits under a sub-category.
        $reported = '0.00';
        foreach ($statement as $row) {
            $reported = bcadd($reported, (string) ($row['ending'] ?? '0.00'), 2);
        }

        $book = '0.00';
        foreach ($ctx->book->productIds() as $productId) {
            $book = bcadd($book, $verify->inventoryState($productId)['value'], 2);
        }

        if (bccomp($book, $reported, 2) === 0) {
            $this->log->checkPassed();
        } else {
            $this->log->finding(
                prefix: 'RPT',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'The inventory value statement disagrees with the stock balances it summarises',
                operation: 'Inventory reporting',
                expected: $book,
                actual: $reported,
                difference: bcsub($book, $reported, 2),
                source: 'StockLedgerService::categoryValueStatement() vs ProductStockBalance',
                rootCause: 'The statement is computed by summing stock_movements.wac_total_value per product '
                    .'category, while the stock balance screen reads ProductStockBalance directly. Those two '
                    .'diverge the moment anything changes a balance WITHOUT writing a movement — which '
                    .'VarianceClosingService does deliberately, adjusting ProductStockBalance to absorb '
                    .'manufacturing variance into finished goods. A product with no category assigned would '
                    .'also fall out of the statement entirely, since it groups on products.category_id.',
                evidence: 'Two screens in the same menu report different inventory values for the same company '
                    .'on the same day.',
                context: ['sum of stock balances' => $book, 'statement total' => $reported],
            );
        }

        $this->log->operation('inventory value statement');
    }

    // ══════════════════════════════════════════════════════════════
    //  The rest — executed against real history, shape-checked
    // ══════════════════════════════════════════════════════════════

    /**
     * Dashboards, analytics and projections have no accounting
     * identity to check them against — there is no second way to
     * compute "top five expense categories". What CAN be tested is
     * that they survive a company with a full year of real, awkward
     * data: back-dated documents, reversed entries, foreign currency,
     * partially settled invoices, zero-quantity months.
     *
     * Each one is recorded as covered only if it returns something.
     */
    private function reportsRun(CycleContext $ctx, string $from, string $to): void
    {
        $companyId  = $ctx->companyId;
        $company    = \App\Models\Company::withoutGlobalScopes()->findOrFail($companyId);
        $currencies = app(CashStatusDashboardService::class)->currencies($companyId);
        $period     = [['key' => 'p', 'label' => 'cycle', 'start_date' => $from, 'end_date' => $to]];
        $filters    = ['date_from' => $from, 'date_to' => $to];

        $reports = [
            'accounting dashboard'   => fn () => app(AccountingDashboardService::class)->build($companyId, $company),
            'HR dashboard'           => fn () => app(HrDashboardService::class)->build($companyId),
            'sales order dashboard'  => fn () => app(SalesOrderDashboardService::class)->build($companyId),
            'contract dashboard'     => fn () => app(ContractDashboardService::class)->build($companyId, $from, $to),
            'cash flow statement'    => fn () => app(CashFlowStatementService::class)->build($companyId, $period),
            'cash flow projection'   => fn () => app(CashFlowProjectionService::class)
                ->build($companyId, $from, $to, 'monthly', $currencies),
            'LG/LC status dashboard' => fn () => app(LgLcStatusDashboardService::class)->build($companyId, $currencies),
            'LG statement'           => fn () => app(LgStatementService::class)->movements($companyId, $filters),
            'LC statement'           => fn () => app(LcStatementService::class)->movements($companyId, $filters),
            'withdrawal statement'   => fn () => app(WithdrawalStatementService::class)->withdrawals($companyId, $filters),
            'expense breakdown'      => fn () => app(ExpenseAnalysisService::class)->accountBreakdown($companyId, $filters),
            'expense trend'          => fn () => app(ExpenseAnalysisService::class)->trend($companyId, $filters),
            'expense breakeven'      => fn () => app(ExpenseAnalysisService::class)->breakeven($companyId, $from, $to),
            'expense run rate'       => fn () => app(ExpenseAnalysisService::class)->runRateProjection($companyId, $from, $to),
            'expense KPIs'           => fn () => app(ExpenseAnalysisService::class)->dashboardKpis($companyId, $from, $to),
            'sales breakdowns'       => fn () => app(SalesAnalysisService::class)->breakdowns($companyId, $from, $to),
            'sales ranking'          => fn () => app(SalesAnalysisService::class)->ranking(
                $companyId, $filters + ['dimension1' => 'customer', 'dimension2' => 'product']),
            'sales matrix'           => fn () => app(SalesAnalysisService::class)->matrix(
                $companyId, $filters + ['dimension1' => 'customer', 'dimension2' => 'product']),
            'sales by customer'      => fn () => app(SalesAnalysisService::class)->singleDimension(
                $companyId, $filters + ['dimension1' => 'customer']),
            'customer nature'        => fn () => app(SalesAnalysisService::class)->customerNature($companyId, $filters),
            'expense categories'     => fn () => app(ExpenseAnalysisService::class)->categoryBreakdown($companyId, $filters),
            'expense min/avg/max'    => fn () => app(ExpenseAnalysisService::class)->minAvgMax($companyId, $filters),
            'top expense items'      => fn () => app(ExpenseAnalysisService::class)->topExpenseItems($companyId, $from, $to),
            'sales trend'            => fn () => app(SalesAnalysisService::class)->trend($companyId, $filters),
            'sales update'           => fn () => app(SalesAnalysisService::class)->salesUpdate($companyId, $from, $to),
            'customer statement'     => fn () => app(CustomerBalanceService::class)
                ->statement($companyId, $ctx->customers[0]->id, null, $from, $to),
            'supplier statement'     => fn () => app(SupplierBalanceService::class)
                ->statement($companyId, $ctx->suppliers[0]->id, null, $from, $to),
        ];

        foreach ($reports as $name => $build) {
            try {
                $result = $build();
            } catch (\Throwable $e) {
                $this->reportFailed("The {$name}", $e, $this->originOf($e));

                continue;
            }

            if ($result === null) {
                $this->log->finding(
                    prefix: 'RPT',
                    severity: Finding::SEVERITY_MEDIUM,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: "The {$name} returned nothing for a company with a full year of history",
                    operation: 'Reporting',
                    expected: 'a report',
                    actual: 'null',
                );

                continue;
            }

            $this->log->operation('report read');
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Tenancy — do the reports actually filter by company?
    // ══════════════════════════════════════════════════════════════

    /**
     * Every model in this system carries a global CompanyScope, and
     * most services lean on it instead of filtering themselves. The
     * scope's first line is:
     *
     *     if (auth()->check() && app()->bound('currentCompanyId'))
     *
     * — so OUTSIDE a web request it does nothing at all. A service
     * that takes $companyId and never uses it is therefore
     * unfiltered in every console command, queue job and scheduled
     * task, while looking correctly scoped in the browser.
     *
     * This cycle is exactly that context, and it has TWO companies
     * with data, which is the only way the difference is visible. The
     * check is simple: does the report for company A contain only
     * company A's money?
     */
    private function reportsAreCompanyScoped(CycleContext $ctx, Verify $verify): bool
    {
        $mine = (string) (CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->sum('amount_outstanding') ?: '0.00');

        $everyone = (string) (CustomerInvoice::withoutGlobalScopes()
            ->where('status', 'posted')
            ->sum('amount_outstanding') ?: '0.00');

        // Nothing to prove until a second company has data of its own.
        if (bccomp($mine, $everyone, 2) === 0) {
            return true;
        }

        try {
            $tabs = app(CustomerBalanceService::class)->currencyTabs($ctx->companyId);
        } catch (\Throwable $e) {
            $this->reportFailed('The customer balances currency tabs', $e, 'CustomerBalanceService::currencyTabs()');

            return false;
        }

        $main = '0.00';
        foreach ($tabs as $tab) {
            if (($tab['code'] ?? null) === 'MAIN') {
                $main = (string) $tab['total'];
            }
        }

        if (bccomp($main, $mine, 2) === 0) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: 'TEN',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: 'Financial reports are not filtered by company outside a web request',
            operation: 'Receivables reporting',
            expected: "this company's own open receivables, {$mine}",
            actual: "every company's open receivables, {$main}",
            difference: bcsub($main, $mine, 2),
            source: 'CustomerBalanceService and SupplierBalanceService — and CompanyScope::apply()',
            rootCause: 'CompanyScope::apply() begins "if (auth()->check() && app()->bound(\'currentCompanyId\'))", '
                .'so the global scope silently applies NO filter whenever there is no authenticated user — every '
                .'console command, queue job and scheduled task. CustomerBalanceService and SupplierBalanceService '
                .'accept a $companyId argument and never use it in their queries, relying entirely on that scope. '
                .'InvoiceAgingService, by contrast, writes where(\'company_id\', $companyId) itself and is correct '
                .'in every context — so the fix has a model already in the codebase.',
            evidence: 'Run from an artisan command with two companies present, the receivables report returns the '
                .'sum of BOTH companies. The same code in the browser returns the right answer, which is why this '
                .'has never been noticed.',
            context: [
                'this company only' => $mine,
                'all companies'     => $everyone,
                'report returned'   => $main,
            ],
        );

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function reportFailed(string $what, \Throwable $e, string $source): void
    {
        $this->log->finding(
            prefix: 'RPT',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: "{$what} could not be produced",
            operation: 'Reporting',
            expected: 'the report to be produced for a company with a full year of history',
            actual: get_class($e).': '.$e->getMessage(),
            source: $source,
            rootCause: 'The report is reachable from the menu, so a user can hit this.',
        );
    }

    /**
     * The posted balance of every leaf under one account group,
     * summed with the cycle's own SQL rather than the reporting
     * services' — so the comparison is genuinely independent.
     */
    private function groupBalance(CycleContext $ctx, string $prefix): string
    {
        $total = '0.00';

        $accounts = \App\Models\ChartOfAccount::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('company_id', $ctx->companyId)->orWhereNull('company_id'))
            ->where('code', 'like', $prefix.'%')
            ->pluck('id');

        $verify = new Verify($this->log, $ctx->companyId);

        foreach ($accounts as $id) {
            $total = bcadd($total, $verify->reportedBalance((int) $id), 2);
        }

        return $total;
    }

    /**
     * Net movement on one account group, broken down by the KIND of
     * document that caused it. Turns "the control account is out by
     * 234,042" into "these four document types touched it".
     *
     * @return array<string, string>
     */
    private function movementBySourceType(CycleContext $ctx, string $prefix): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->where('l.company_id', $ctx->companyId)
            ->where('e.status', 'posted')
            ->where('a.code', 'like', $prefix.'%')
            ->groupBy('e.source_type')
            ->selectRaw('e.source_type, COALESCE(SUM(l.debit - l.credit), 0) as net')
            ->orderBy('e.source_type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out['moved by '.($row->source_type ?: 'manual entry')] = (string) $row->net;
        }

        return $out;
    }

    /**
     * When the gap between a control account and its subledger is
     * EXACTLY the movement one kind of document made, that document
     * is the cause — it moved the general ledger without moving the
     * invoice or bill behind it. Saying so turns a number into a
     * place to look.
     *
     * @return array<string, string>
     */
    private function attribution(CycleContext $ctx, string $prefix, string $difference): array
    {
        if (bccomp($difference, '0', 2) === 0) {
            return [];
        }

        $target = $this->magnitude($difference);

        foreach ($this->movementBySourceType($ctx, $prefix) as $label => $net) {
            if (bccomp($this->magnitude($net), $target, 2) === 0) {
                return ['⟶ the gap is exactly the movement ' => trim(str_replace('moved by', '', $label))
                    .' made — it moved the control account without moving the document it settled'];
            }
        }

        return [];
    }

    /** A liability balance read as a positive amount owed. */
    private function magnitude(string $balance): string
    {
        return bccomp($balance, '0', 2) < 0 ? bcmul($balance, '-1', 2) : $balance;
    }

    private function originOf(\Throwable $e): string
    {
        return basename($e->getFile()).':'.$e->getLine();
    }
}
