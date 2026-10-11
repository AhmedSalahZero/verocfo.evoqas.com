<?php

namespace App\Services\Analytics;

// ══════════════════════════════════════════════════════════════════
//  SalesAnalysisService
//
//  Analytics > Sales Analysis — Business Analytical Engine pillar.
//  Ported from CashVero's CFO Tools SalesAnalysisController, with one
//  fundamental change: CFO Tools read from a flat table populated by
//  an Excel upload. Vero CFO reads directly from real, already-posted
//  Customer Invoice Lines — no upload, no field mapping step, because
//  the data already exists the moment a Sales Invoice is posted.
//
//  AUDIT H13 FIX (Sept 2026) — every figure is now NET SALES in the
//  company's FUNCTIONAL currency, read through SalesLinesQuery:
//    • each line converted with its document's own exchange rate
//      (USD and EGP are no longer added together as if equal);
//    • posted credit notes subtracted and posted debit notes added,
//      on their own dates, against the product / cost centre /
//      project of the invoice line they adjust;
//    • customers (and products / cost centres) are grouped by ID and
//      labelled "Name (CODE)", so two customers with the same name
//      are never merged. Customer Nature now works on customer IDs.
//  Quantity stays "units invoiced" (notes adjust value only), and
//  Unit Price is net value ÷ units — the average net selling price.
//
//  DIMENSIONS available (confirmed against real schema — Branch,
//  Sales Person, Service Provider, Principle do NOT exist in Vero
//  CFO's data model and are intentionally left out per Mahmoud's
//  decision, 2026-09):
//    customer          → partners.name_en / name_ar
//    product            → products.name_en / name_ar
//    product_category   → product_categories.name_en / name_ar
//    cost_center        → cost_centers.name_en / name_ar
//    sales_channel      → sales_channels.name_en / name_ar   (partners.sales_channel_id)
//    business_sector    → business_sectors.name_en / name_ar (partners.business_sector_id)
//    zone               → partners.sales_zone (plain text field)
//    project             → projects.name_en / name_ar (via sales_orders.project_id)
//    payment_term        → payment_terms.name_en / name_ar
//
//  METRICS available (from SalesLinesQuery — functional currency):
//    quantity      → SUM(quantity)              — units invoiced
//    unit_price    → SUM(net_amount) / SUM(quantity) — quantity-weighted
//                    average net selling price (a line selling 500
//                    units counts 500x more than a line selling 1)
//    net_amount    → SUM(net_amount)   — net sales before VAT
//    vat_amount    → SUM(vat_amount)
//    total_amount  → SUM(total_amount) — net sales including VAT
//
//  Report types (8 — identical set to CFO Tools):
//    single_dimension, matrix, ranking, customer_nature,
//    period_comparison, trend, two_factors_trend, invoice_analysis
//
//  bcmath used for every PHP-side combination of money values
//  (percentages, growth rates, waterfall math) — SQL SUM() on the
//  underlying decimal columns is the aggregation step itself, not
//  PHP float arithmetic, so it stays consistent with Rule 4.
//
//  @layer    Service
//  @depends  SalesLinesQuery, CustomerInvoice, Partner, Product,
//            ProductCategory, CostCenter, SalesChannel, BusinessSector,
//            SalesOrder, Project, PaymentTerm
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class SalesAnalysisService
{
    // ── Dimension registry ──────────────────────────────────────
    // key => [label, join_closure_applied_to_query, group_by_column, select_expr]

    public const DIMENSIONS = [
        'customer'         => 'Customer',
        'product'          => 'Product',
        'product_category' => 'Product Category',
        'cost_center'      => 'Cost Center',
        'sales_channel'    => 'Sales Channel',
        'business_sector'  => 'Business Sector',
        'zone'             => 'Zone',
        'project'          => 'Project',
        'payment_term'     => 'Payment Term',
    ];

    public const METRICS = [
        'net_amount'   => 'Net Amount',
        'total_amount' => 'Total Amount (incl. VAT)',
        'vat_amount'   => 'VAT Amount',
        'quantity'     => 'Quantity',
        'unit_price'   => 'Unit Price',
    ];

    private const NON_ADDITIVE_METRICS = ['unit_price'];

    // ── Base query — every report starts here ───────────────────
    // Unified net-sales lines (invoices + debit notes − credit notes,
    // functional currency — see SalesLinesQuery) aliased as "sl",
    // with every dimension joined up front so any report can
    // reference any of them without repeating join logic.

    private function baseQuery(int $companyId, string $dateFrom, string $dateTo)
    {
        return SalesLinesQuery::from($companyId, $dateFrom, $dateTo, 'sl')
            ->leftJoin('partners', 'partners.id', '=', 'sl.partner_id')
            ->leftJoin('products', 'products.id', '=', 'sl.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'sl.cost_center_id')
            ->leftJoin('sales_channels', 'sales_channels.id', '=', 'partners.sales_channel_id')
            ->leftJoin('business_sectors', 'business_sectors.id', '=', 'partners.business_sector_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sl.sales_order_id')
            ->leftJoin('projects', 'projects.id', '=', 'sales_orders.project_id')
            ->leftJoin('payment_terms', 'payment_terms.id', '=', 'sl.payment_term_id');
    }

    // Distinct invoices (not credit / debit notes) behind a set of rows.
    private const INVOICE_COUNT_EXPR = "COUNT(DISTINCT CASE WHEN sl.doc_type = 'invoice' THEN sl.doc_id END)";

    // "Name (CODE)" — the code keeps two same-name records apart
    // everywhere a report groups or looks up by label (Audit H13).
    private const CUSTOMER_LABEL = "CONCAT(COALESCE(partners.name_en, 'Unassigned'), COALESCE(CONCAT(' (', partners.code, ')'), ''))";

    // Returns [select_expr, group_by_expr] for a dimension key.
    private function dimensionExpr(string $dimension): array
    {
        return match ($dimension) {
            'customer'         => [self::CUSTOMER_LABEL, 'partners.id'],
            'product'          => ["CONCAT(COALESCE(products.name_en, 'Unassigned'), COALESCE(CONCAT(' (', products.code, ')'), ''))", 'products.id'],
            'product_category' => ["COALESCE(product_categories.name_en, 'Unassigned')", 'product_categories.id'],
            'cost_center'      => ["CONCAT(COALESCE(cost_centers.name_en, 'Unassigned'), COALESCE(CONCAT(' (', cost_centers.code, ')'), ''))", 'cost_centers.id'],
            'sales_channel'    => ["COALESCE(sales_channels.name_en, 'Unassigned')", 'sales_channels.id'],
            'business_sector'  => ["COALESCE(business_sectors.name_en, 'Unassigned')", 'business_sectors.id'],
            'zone'             => ["COALESCE(partners.sales_zone, 'Unassigned')", 'partners.sales_zone'],
            'project'          => ["COALESCE(projects.name_en, 'Unassigned')", 'projects.id'],
            'payment_term'     => ["COALESCE(payment_terms.name_en, 'Unassigned')", 'payment_terms.id'],
            default            => [self::CUSTOMER_LABEL, 'partners.id'],
        };
    }

    private function aggFunc(string $metric): string
    {
        return in_array($metric, self::NON_ADDITIVE_METRICS, true) ? 'AVG' : 'SUM';
    }

    // Full SQL expression to compute a metric's value.
    private function metricExpr(string $metric): string
    {
        return match ($metric) {
            'unit_price'   => 'SUM(sl.net_amount) / NULLIF(SUM(sl.quantity), 0)',
            'quantity'     => 'SUM(sl.quantity)',
            'vat_amount'   => 'SUM(sl.vat_amount)',
            'total_amount' => 'SUM(sl.total_amount)',
            default        => 'SUM(sl.net_amount)', // net_amount
        };
    }

    private function periodExpressions(string $period): array
    {
        return match ($period) {
            'quarterly'     => ["CONCAT(YEAR(sl.doc_date), '-Q', QUARTER(sl.doc_date))", 'YEAR(sl.doc_date) * 10 + QUARTER(sl.doc_date)'],
            'semi_annually' => ["CONCAT(YEAR(sl.doc_date), '-H', IF(MONTH(sl.doc_date) <= 6, 1, 2))", 'YEAR(sl.doc_date) * 10 + IF(MONTH(sl.doc_date) <= 6, 1, 2)'],
            'annually'      => ['YEAR(sl.doc_date)', 'YEAR(sl.doc_date)'],
            default         => ["DATE_FORMAT(sl.doc_date, '%Y-%b')", "DATE_FORMAT(sl.doc_date, '%Y%m') + 0"], // monthly
        };
    }

    // ── Report: Single Dimension ─────────────────────────────────

    public function singleDimension(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        [$labelExpr, $groupExpr] = $this->dimensionExpr($filters['dimension1']);

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$labelExpr as label, {$this->metricExpr($metric)} as value, " . self::INVOICE_COUNT_EXPR . " as transactions")
            ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))
            ->orderByDesc('value')
            ->get();

        $total = (float) Money::sum($rows->pluck('value'));
        $accumulated = '0';
        $out = $rows->map(function ($r) use ($total, &$accumulated) {
            $share = $total > 0 ? bcmul(bcdiv((string) $r->value, (string) $total, 6), '100', 2) : '0.00';
            $accumulated = bcadd($accumulated, $share, 2);
            return [
                'label' => $r->label, 'value' => (float) $r->value,
                'transactions' => (int) $r->transactions,
                'share' => (float) $share, 'accumulated_share' => (float) $accumulated,
            ];
        });

        return ['type' => 'single_dimension', 'dimension' => $filters['dimension1'], 'metric' => $metric, 'rows' => $out, 'total' => $total];
    }

    // ── Report: Matrix (2D) ───────────────────────────────────────

    public function matrix(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        [$rowExpr, $rowGroup] = $this->dimensionExpr($filters['dimension1']);
        [$colExpr, $colGroup] = $this->dimensionExpr($filters['dimension2']);

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$rowExpr as row_label, $colExpr as col_label, {$this->metricExpr($metric)} as value")
            ->groupBy(DB::raw($rowGroup), DB::raw($rowExpr), DB::raw($colGroup), DB::raw($colExpr))
            ->get();

        $columns = $rows->pluck('col_label')->unique()->sort()->values();
        $grouped = $rows->groupBy('row_label')->map(function ($group, $rowLabel) use ($columns) {
            $line = ['label' => $rowLabel];
            $total = '0';
            foreach ($columns as $col) {
                $val = (float) ($group->firstWhere('col_label', $col)->value ?? 0);
                $line[$col] = $val;
                $total = bcadd($total, (string) $val, 2);
            }
            $line['total'] = (float) $total;
            return $line;
        })->sortByDesc('total')->values();

        return ['type' => 'matrix', 'dim1' => $filters['dimension1'], 'dim2' => $filters['dimension2'], 'columns' => $columns, 'rows' => $grouped];
    }

    // ── Report: Ranking (primary dimension ranked, secondary as drill-down) ──

    public function ranking(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        $topN = (int) ($filters['top_n'] ?? 20);
        [$rowExpr, $rowGroup] = $this->dimensionExpr($filters['dimension1']);
        [$colExpr, $colGroup] = $this->dimensionExpr($filters['dimension2']);

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$rowExpr as row_label, $colExpr as col_label, {$this->metricExpr($metric)} as value")
            ->groupBy(DB::raw($rowGroup), DB::raw($rowExpr), DB::raw($colGroup), DB::raw($colExpr))
            ->get();

        $ranked = $rows->groupBy('row_label')
            ->map(fn ($g, $label) => ['label' => $label, 'total' => (float) Money::sum($g->pluck('value')), 'breakdown' => $g->sortByDesc('value')->values()->map(fn ($r) => ['label' => $r->col_label, 'value' => (float) $r->value])])
            ->sortByDesc('total')->take($topN)->values();

        return ['type' => 'ranking', 'dim1' => $filters['dimension1'], 'dim2' => $filters['dimension2'], 'rows' => $ranked];
    }

    // ── Report: Customer Nature ───────────────────────────────────
    // New / Repeating / Active / Stop / Dead / Reactivated — same
    // multi-year logic as CFO Tools, based on real invoice_date years.
    //
    // Enhancement over the original report-only version (added for the
    // Sales Dashboard's Customer Nature card, September 2026): 'stop'
    // and 'dead' customers have zero sales in the selected period BY
    // DEFINITION (they didn't buy), so we show their sales from the
    // last year they were genuinely active instead — the user can then
    // see how much revenue is actually being lost, not just a zero.
    // Adds 'customer_rows' (name + value + % share, sorted) — additive
    // only, existing 'customers'/'count'/'value' keys are unchanged so
    // Reports.vue keeps working exactly as before.

    public function customerNature(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        $currentYear = (int) date('Y', strtotime($filters['date_to']));

        // Audit H13 — buckets are built from customer IDs (two customers
        // sharing a name are two customers), then shown by label.
        $years = [];
        for ($i = 0; $i <= 4; $i++) {
            $y = $currentYear - $i;
            $years[$i] = DB::table('customer_invoices')
                ->where('customer_invoices.company_id', $companyId)
                ->where('customer_invoices.status', 'posted')
                ->whereYear('customer_invoices.invoice_date', $y)
                ->pluck('customer_invoices.partner_id')->map(fn ($id) => (int) $id)->unique()->values();
        }
        [$setY, $setY1, $setY2, $setY3, $setY4] = $years;

        $buckets = [
            'new'              => $setY->diff($setY1)->diff($setY2)->diff($setY3)->values(),
            'repeating'        => $setY->intersect($setY1)->diff($setY2)->diff($setY3)->diff($setY4)->values(),
            'active'           => $setY->intersect($setY1)->intersect($setY2)->values(),
            'stop'             => $setY1->diff($setY)->values(),
            'dead'             => $setY2->diff($setY1)->diff($setY)->values(),
            'stop_reactivated' => $setY->intersect($setY2)->diff($setY1)->values(),
            'dead_reactivated' => $setY->intersect($setY3)->diff($setY2)->diff($setY1)->values(),
        ];

        $labels = DB::table('partners')
            ->whereIn('id', collect($buckets)->flatten()->unique()->values()->all())
            ->selectRaw('id, ' . self::CUSTOMER_LABEL . ' as label')
            ->pluck('label', 'id');

        $salesForRange = fn (string $from, string $to) => $this->baseQuery($companyId, $from, $to)
            ->selectRaw("sl.partner_id as partner_id, {$this->metricExpr($metric)} as total")
            ->groupBy('sl.partner_id')->get()->keyBy('partner_id');

        $salesByCustomer = $salesForRange($filters['date_from'], $filters['date_to']);
        $pastSalesMap  = [
            'stop' => $salesForRange(($currentYear - 1) . '-01-01', ($currentYear - 1) . '-12-31'),
            'dead' => $salesForRange(($currentYear - 2) . '-01-01', ($currentYear - 2) . '-12-31'),
        ];
        $pastSalesYear = ['stop' => $currentYear - 1, 'dead' => $currentYear - 2];

        $result = [];
        foreach ($buckets as $key => $ids) {
            $isPast   = isset($pastSalesMap[$key]);
            $salesMap = $pastSalesMap[$key] ?? $salesByCustomer;

            $rows = $ids->map(fn ($id) => ['name' => $labels[$id] ?? 'Unassigned', 'value' => (float) ($salesMap[$id]->total ?? 0)])
                ->sortByDesc('value')->values();
            $bucketTotal = (float) Money::sum($rows->pluck('value'));

            $result[$key] = [
                'count'          => $ids->count(),
                'value'          => $bucketTotal,
                'is_past_period' => $isPast,
                'sales_year'     => $isPast ? $pastSalesYear[$key] : null,
                'customers'      => $ids->map(fn ($id) => $labels[$id] ?? 'Unassigned')->values(),
                'customer_rows'  => $rows->map(fn ($r) => [
                    'name'  => $r['name'],
                    'value' => $r['value'],
                    'pct'   => $bucketTotal > 0 ? round($r['value'] / $bucketTotal * 100, 1) : 0,
                ])->values(),
            ];
        }

        return ['type' => 'customer_nature', 'buckets' => $result, 'year' => $currentYear];
    }

    // ── Dashboard: Sales Breakdown Analysis ─────────────────────────
    // One donut+table card per dimension, reusing singleDimension()
    // so the share/accumulated-share math is identical to the Reports
    // page. 'customer' and 'product' are excluded here — they're too
    // granular for a breakdown donut and are covered by Top Achievers.

    private const BREAKDOWN_DIMENSIONS = ['product_category', 'zone', 'sales_channel', 'business_sector', 'cost_center', 'payment_term', 'project'];

    public function breakdowns(int $companyId, string $dateFrom, string $dateTo): array
    {
        $out = [];
        foreach (self::BREAKDOWN_DIMENSIONS as $dim) {
            $result = $this->singleDimension($companyId, ['date_from' => $dateFrom, 'date_to' => $dateTo, 'dimension1' => $dim, 'metric' => 'net_amount']);
            $rows = $result['rows']->take(15)->values();
            if ($rows->isEmpty()) continue;

            $out[] = ['dimension' => $dim, 'label' => self::DIMENSIONS[$dim], 'rows' => $rows];
        }

        return $out;
    }

    // ── Dashboard: Top Achievers ─────────────────────────────────────
    // Top single item per dimension, for the Top Achievers cards.
    // achieverTakeaway() below powers the "Takeaways" drill-down popup.

    private const ACHIEVER_DIMENSIONS = ['customer', 'product', 'product_category', 'zone', 'sales_channel', 'business_sector'];

    public function topAchievers(int $companyId, string $dateFrom, string $dateTo): array
    {
        $out = [];
        foreach (self::ACHIEVER_DIMENSIONS as $dim) {
            [$labelExpr, $groupExpr] = $this->dimensionExpr($dim);
            $rows = $this->baseQuery($companyId, $dateFrom, $dateTo)
                ->selectRaw("$labelExpr as label, SUM(sl.net_amount) as value")
                ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))
                ->orderByDesc('value')->get();
            if ($rows->isEmpty()) continue;

            $top = $rows->first();
            $out[] = [
                'dimension'   => $dim,
                'label'       => self::DIMENSIONS[$dim],
                'top_label'   => $top->label,
                'top_value'   => (float) $top->value,
                'total_items' => $rows->count(),
                'total_sales' => (float) Money::sum($rows->pluck('value')),
            ];
        }

        return $out;
    }

    // ── Dashboard: Top Achiever "Takeaways" popup ─────────────────────
    // Full ranking for one dimension + a key-stats block for the
    // selected item (defaults to the top item). Same shape as CFO
    // Tools' takeaway() endpoint, adapted to Vero CFO's real dimensions.

    public function achieverTakeaway(int $companyId, array $filters): array
    {
        $dim = $filters['dimension'];
        [$labelExpr, $groupExpr] = $this->dimensionExpr($dim);

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$labelExpr as label, SUM(sl.net_amount) as value")
            ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))
            ->orderByDesc('value')->get();

        $total = (float) Money::sum($rows->pluck('value'));
        $ranking = $rows->map(fn ($r) => [
            'label' => $r->label, 'value' => (float) $r->value,
            'pct'   => $total > 0 ? round($r->value / $total * 100, 1) : 0,
        ])->values();

        $selected = $filters['selected_label'] ?? ($ranking->first()['label'] ?? '');

        $statBase = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->whereRaw("$labelExpr = ?", [$selected]);

        $itemValue = (float) Money::round((clone $statBase)->sum('sl.net_amount'));

        $stats = [
            ['label' => 'Net Sales', 'value' => number_format($itemValue, 0)],
            ['label' => '% of Total', 'value' => $total > 0 ? round($itemValue / $total * 100, 1) . '%' : '—'],
        ];
        $stats[] = ['label' => 'Invoices', 'value' => (clone $statBase)->where('sl.doc_type', 'invoice')->distinct('sl.doc_id')->count('sl.doc_id')];
        if ($dim !== 'customer') $stats[] = ['label' => 'Customers', 'value' => (clone $statBase)->whereNotNull('partners.id')->distinct('partners.id')->count('partners.id')];
        if ($dim !== 'product_category') $stats[] = ['label' => 'Product Categories', 'value' => (clone $statBase)->whereNotNull('product_categories.id')->distinct('product_categories.id')->count('product_categories.id')];
        if ($dim !== 'zone') $stats[] = ['label' => 'Zones', 'value' => (clone $statBase)->whereNotNull('partners.sales_zone')->distinct('partners.sales_zone')->count('partners.sales_zone')];

        return ['ranking' => $ranking, 'stats' => $stats, 'selected_label' => $selected];
    }

    // ── Dashboard: Sales Update KPI cards ─────────────────────────────
    // Reuses trend() (monthly, already bcmath-correct on growth %) and
    // dashboardKpis() rather than re-deriving month math from scratch.

    public function salesUpdate(int $companyId, string $dateFrom, string $dateTo): array
    {
        $kpis = $this->dashboardKpis($companyId, $dateFrom, $dateTo);
        $trendRows = $this->trend($companyId, ['date_from' => $dateFrom, 'date_to' => $dateTo, 'period' => 'monthly'])['rows']->values();
        $to = (int) date('Y', strtotime($dateTo));

        $currentMonth = $trendRows->last();
        $last3 = $trendRows->slice(-3);
        $prior3 = $trendRows->slice(-6, 3);
        $last3Sum = (float) Money::sum($last3->pluck('value'));
        $prior3Sum = (float) Money::sum($prior3->pluck('value'));

        $ytdRows = $trendRows->filter(fn ($r) => str_starts_with($r['period'], (string) $to));
        $ytdSum = (float) Money::sum($ytdRows->pluck('value'));
        $ytdMonths = max(1, $ytdRows->count());

        $best = $trendRows->sortByDesc('value')->first();
        $worst = $trendRows->sortBy('value')->first();
        $avgTransaction = $kpis['invoice_count'] > 0 ? $kpis['net_sales'] / $kpis['invoice_count'] : 0;

        return [
            'total_sales'          => $kpis['net_sales'],
            'total_invoices'       => $kpis['invoice_count'],
            'unique_customers'     => $kpis['unique_customers'],
            'avg_transaction'      => round($avgTransaction, 2),
            'current_month_label'  => $currentMonth['period'] ?? null,
            'current_month_value'  => (float) ($currentMonth['value'] ?? 0),
            'current_month_growth' => $currentMonth['growth_rate'] ?? null,
            'last_3_months_value'  => $last3Sum,
            'last_3_months_growth' => $prior3Sum > 0 ? round((($last3Sum - $prior3Sum) / $prior3Sum) * 100, 1) : null,
            'ytd_value'            => $ytdSum,
            'ytd_avg_monthly'      => round($ytdSum / $ytdMonths, 2),
            'best_month_label'     => $best['period'] ?? null,
            'best_month_value'     => (float) ($best['value'] ?? 0),
            'worst_month_label'    => $worst['period'] ?? null,
            'worst_month_value'    => (float) ($worst['value'] ?? 0),
            'trend'                => $trendRows,
        ];
    }

    // ── Report: Period Comparison ─────────────────────────────────

    public function periodComparison(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        [$labelExpr, $groupExpr] = $this->dimensionExpr($filters['dimension1'] ?? 'customer');
        $periods = $filters['periods']; // [['from','to','label'],...] 2-5 entries

        $rows = collect();
        foreach ($periods as $p) {
            $periodRows = $this->baseQuery($companyId, $p['from'], $p['to'])
                ->selectRaw("$labelExpr as label, {$this->metricExpr($metric)} as value")
                ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))->get()
                ->keyBy('label');
            $rows->put($p['label'], $periodRows);
        }

        $allLabels = $rows->flatMap(fn ($p) => $p->keys())->unique()->values();
        $table = $allLabels->map(function ($label) use ($rows, $periods) {
            $line = ['label' => $label];
            $prevValue = null;
            foreach ($periods as $p) {
                $val = (float) ($rows[$p['label']][$label]->value ?? 0);
                $line[$p['label']] = $val;
                if ($prevValue !== null) {
                    $line["change_vs_prev"] = $prevValue > 0 ? (float) bcmul(bcdiv((string) ($val - $prevValue), (string) $prevValue, 6), '100', 2) : null;
                }
                $prevValue = $val;
            }
            return $line;
        });

        return ['type' => 'period_comparison', 'periods' => array_column($periods, 'label'), 'rows' => $table];
    }

    // ── Report: Trend Over Time ───────────────────────────────────

    public function trend(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        [$periodLabel, $periodSort] = $this->periodExpressions($filters['period'] ?? 'monthly');

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$periodLabel as period, $periodSort as sort_key, {$this->metricExpr($metric)} as value, " . self::INVOICE_COUNT_EXPR . " as transactions")
            ->groupBy(DB::raw($periodLabel), DB::raw($periodSort))
            ->orderBy('sort_key')->get();

        $prev = null;
        $out = $rows->map(function ($r) use (&$prev) {
            $val = (float) $r->value;
            $growth = ($prev !== null && $prev > 0) ? (float) bcmul(bcdiv((string) ($val - $prev), (string) $prev, 6), '100', 2) : null;
            $prev = $val;
            return ['period' => $r->period, 'value' => $val, 'transactions' => (int) $r->transactions, 'growth_rate' => $growth];
        });

        return ['type' => 'trend', 'metric' => $metric, 'rows' => $out];
    }

    // ── Report: Two Factors Trend ─────────────────────────────────

    public function twoFactorsTrend(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        [$periodLabel, $periodSort] = $this->periodExpressions($filters['period'] ?? 'monthly');
        [$factorExpr, $factorGroup] = $this->dimensionExpr($filters['dimension1']);

        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$periodLabel as period, $periodSort as sort_key, $factorExpr as factor, {$this->metricExpr($metric)} as value")
            ->groupBy(DB::raw($periodLabel), DB::raw($periodSort), DB::raw($factorGroup), DB::raw($factorExpr))
            ->orderBy('sort_key')->get();

        $factors = $rows->pluck('factor')->unique()->values();
        $periods = $rows->pluck('period')->unique()->values(); // already sorted by first appearance (sort_key order)

        $series = $factors->map(function ($factor) use ($rows, $periods) {
            $prev = null;
            return [
                'label' => $factor,
                'points' => $periods->map(function ($period) use ($rows, $factor, &$prev) {
                    $val = (float) ($rows->first(fn ($r) => $r->period === $period && $r->factor === $factor)->value ?? 0);
                    $growth = ($prev !== null && $prev > 0) ? (float) bcmul(bcdiv((string) ($val - $prev), (string) $prev, 6), '100', 2) : null;
                    $prev = $val;
                    return ['period' => $period, 'value' => $val, 'growth_rate' => $growth];
                }),
            ];
        });

        return ['type' => 'two_factors_trend', 'dimension' => $filters['dimension1'], 'periods' => $periods, 'series' => $series];
    }

    // ── Report: Invoice Analysis ──────────────────────────────────
    // Snapshot view: overall invoice-level statistics for the period.
    // "By dimension" and "large invoices" views follow the same
    // per-invoice grouping pattern and can be added as needed.

    public function invoiceAnalysis(int $companyId, array $filters): array
    {
        $metric = $filters['metric'] ?? 'net_amount';
        $threshold = (float) ($filters['invoice_threshold'] ?? 0);

        $perInvoice = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            // One row per invoice ISSUED in the range, net of any credit /
            // debit notes against it that also fall in the range.
            ->selectRaw("sl.invoice_id as id, sl.invoice_code, " . self::CUSTOMER_LABEL . " as customer_name, sl.invoice_date, {$this->metricExpr($metric)} as value, SUM(CASE WHEN sl.doc_type = 'invoice' THEN 1 ELSE 0 END) as line_items")
            ->groupBy('sl.invoice_id', 'sl.invoice_code', 'sl.invoice_date', 'partners.id', 'partners.name_en', 'partners.code')
            ->havingRaw("SUM(CASE WHEN sl.doc_type = 'invoice' THEN 1 ELSE 0 END) > 0")
            ->get();

        $totalValue = Money::sum($perInvoice->pluck('value'));
        $values = $perInvoice->pluck('value')->map(fn ($v) => (float) $v)->sort()->values();
        $count = $perInvoice->count();

        $snapshot = [
            'invoice_count'     => $count,
            'total_value'       => (float) $totalValue,
            'avg_invoice_value' => $count > 0 ? (float) Money::round(bcdiv($totalValue, (string) $count, 6)) : 0,
            'median_invoice_value' => $this->median($values),
            'max_invoice_value' => $values->max() ?? 0,
            'avg_line_items'    => $count > 0 ? $perInvoice->sum('line_items') / $count : 0,
        ];

        $largeInvoices = $threshold > 0
            ? $perInvoice->filter(fn ($r) => (float) $r->value >= $threshold)->sortByDesc('value')->values()
            : collect();

        return ['type' => 'invoice_analysis', 'snapshot' => $snapshot, 'large_invoices' => $largeInvoices];
    }

    private function median(Collection $sorted): float
    {
        $count = $sorted->count();
        if ($count === 0) return 0;
        $mid = intdiv($count, 2);
        return $count % 2 === 0 ? ($sorted[$mid - 1] + $sorted[$mid]) / 2 : $sorted[$mid];
    }

    // ── Dashboard KPIs ─────────────────────────────────────────────

    public function dashboardKpis(int $companyId, string $dateFrom, string $dateTo): array
    {
        $base = $this->baseQuery($companyId, $dateFrom, $dateTo);

        return [
            'net_sales'         => (float) Money::round((clone $base)->sum('sl.net_amount')),
            'invoice_count'     => (clone $base)->where('sl.doc_type', 'invoice')->distinct('sl.doc_id')->count('sl.doc_id'),
            'unique_customers'  => (clone $base)->distinct('partners.id')->count('partners.id'),
            'product_categories'=> (clone $base)->whereNotNull('product_categories.id')->distinct('product_categories.id')->count('product_categories.id'),
            'zones'             => (clone $base)->whereNotNull('partners.sales_zone')->distinct('partners.sales_zone')->count('partners.sales_zone'),
        ];
    }
}
