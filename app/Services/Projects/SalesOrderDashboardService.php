<?php

namespace App\Services\Projects;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderDashboardService
//
//  Projects & Contracts > Sales Orders Dashboard (September 2026,
//  per Mahmoud — "same design and colors as the Accounting
//  Dashboard"). Same discipline as AccountingDashboardService: a
//  READ-ONLY aggregation over SalesOrder rows, converting each SO's
//  own native-currency so_value/invoiced_amount to the company's
//  functional currency via that SO's own exchange_rate — the same
//  convention ContractDashboardService already uses for Project
//  contract values (SalesOrder has no *_functional columns of its
//  own the way CustomerInvoice does, so this is done here directly:
//  functional = native × exchange_rate, matching how
//  CustomerBalanceService's nativeAmount() defines the SAME
//  relationship in the other direction — native = functional ÷ rate).
//
//  Fix (September 2026, per Mahmoud — bug report: clicking "This
//  Month" still showed Year-to-Date figures): the first version of
//  this service only scoped ONE card ("New Sales Orders") to the
//  selected period — every other section (backlog, invoicing
//  progress, status mix, top orders/customers) was always computed
//  from ALL open orders regardless of which pill was selected, so
//  toggling the period visibly changed almost nothing. Every section
//  is now scoped to "sales orders ISSUED within the selected
//  period" — the whole page answers "what does September look
//  like", not just one card — computed for both periods together
//  (same one-payload-two-datasets pattern IncomeStatementService
//  uses for the Accounting Dashboard), so the Vue page just switches
//  which period's complete dataset it displays, no second request.
//
//  "Open" throughout this service means status IN (draft, active,
//  on_hold) — closed and cancelled orders contribute to the
//  period's status-mix donut and issued-count, but not to
//  backlog/invoicing-progress figures (nothing left to invoice, or
//  nothing ever will be).
//
//  Top Sales Orders / Top Customers tables link straight back to the
//  real Sales Orders list (projects.sales-orders.index, filtered by
//  status or partner_id) rather than re-implementing that list's
//  own detail a second time — same "full drill-down via the real
//  page" convention as AccountingDashboardService's AR/AP tables.
//
//  bcmath throughout (Rule 4).
//
//  @layer    Service
//  @depends  SalesOrder, Partner
// ══════════════════════════════════════════════════════════════════

use App\Models\SalesOrder;
use Illuminate\Support\Collection;

class SalesOrderDashboardService
{
    private const OPEN_STATUSES = ['draft', 'active', 'on_hold'];

    private const STATUS_ORDER = ['draft', 'active', 'on_hold', 'closed', 'cancelled'];

    public function build(int $companyId): array
    {
        $periods = $this->periods();

        $orders = SalesOrder::with('partner:id,code,name_en,name_ar')
            ->get(['id', 'so_code', 'subject', 'partner_id', 'so_value', 'invoiced_amount', 'exchange_rate', 'status', 'issuance_date']);

        $data = [];
        foreach ($periods as $period) {
            $data[$period['key']] = $this->buildForPeriod($orders, $period);
        }

        return [
            'periods' => array_column($periods, 'label', 'key'),
            'data'    => $data,
            'as_of'   => now()->toDateString(),
        ];
    }

    // ── Periods ──────────────────────────────────────────────────

    private function periods(): array
    {
        $today = now();

        return [
            [
                'key'        => 'month',
                'label'      => $today->format('F Y'),
                'start_date' => $today->copy()->startOfMonth()->toDateString(),
                'end_date'   => $today->toDateString(),
            ],
            [
                'key'        => 'ytd',
                'label'      => 'Year to Date',
                'start_date' => $today->copy()->startOfYear()->toDateString(),
                'end_date'   => $today->toDateString(),
            ],
        ];
    }

    /** Every section, scoped to sales orders whose issuance_date falls inside this one period. */
    private function buildForPeriod(Collection $allOrders, array $period): array
    {
        $ordersInPeriod = $allOrders->filter(fn ($so) => $so->issuance_date
            && $so->issuance_date->toDateString() >= $period['start_date']
            && $so->issuance_date->toDateString() <= $period['end_date']
        )->values();

        $openOrders = $ordersInPeriod->filter(fn ($so) => in_array($so->status, self::OPEN_STATUSES, true))->values();

        return [
            'overview'      => $this->overview($ordersInPeriod, $openOrders),
            'status_mix'    => $this->statusMix($ordersInPeriod),
            'invoicing'     => $this->invoicingProgress($openOrders),
            'top_orders'    => $this->topOrders($openOrders),
            'top_customers' => $this->topCustomers($openOrders),
        ];
    }

    // ── Overview ─────────────────────────────────────────────────

    private function overview(Collection $ordersInPeriod, Collection $openOrders): array
    {
        $issuedValue = '0.00';
        foreach ($ordersInPeriod as $so) {
            $issuedValue = bcadd($issuedValue, $this->functional($so->so_value, $so->exchange_rate), 2);
        }

        $totalBacklogValue = '0.00';
        $totalRemaining = '0.00';
        foreach ($openOrders as $so) {
            $totalBacklogValue = bcadd($totalBacklogValue, $this->functional($so->so_value, $so->exchange_rate), 2);
            $totalRemaining = bcadd($totalRemaining, $this->remaining($so), 2);
        }

        return [
            'issued_count'        => $ordersInPeriod->count(),
            'issued_value'        => $issuedValue,
            'open_order_count'    => $openOrders->count(),
            'total_backlog_value' => $totalBacklogValue,
            'total_remaining'     => $totalRemaining,
        ];
    }

    // ── Status mix ───────────────────────────────────────────────

    private function statusMix(Collection $ordersInPeriod): array
    {
        $sums = array_fill_keys(self::STATUS_ORDER, '0.00');
        $counts = array_fill_keys(self::STATUS_ORDER, 0);

        foreach ($ordersInPeriod as $so) {
            $status = in_array($so->status, self::STATUS_ORDER, true) ? $so->status : 'draft';
            $sums[$status] = bcadd($sums[$status], $this->functional($so->so_value, $so->exchange_rate), 2);
            $counts[$status]++;
        }

        $labels = [
            'draft'     => 'Draft',
            'active'    => 'Active',
            'on_hold'   => 'On Hold',
            'closed'    => 'Closed',
            'cancelled' => 'Cancelled',
        ];

        return collect(self::STATUS_ORDER)
            ->map(fn ($status) => [
                'status'   => $status,
                'category' => $labels[$status],
                'value'    => $sums[$status],
                'count'    => $counts[$status],
            ])
            ->values()
            ->all();
    }

    // ── Invoicing progress ───────────────────────────────────────

    private function invoicingProgress(Collection $openOrders): array
    {
        $totalValue = '0.00';
        $invoiced = '0.00';

        foreach ($openOrders as $so) {
            $totalValue = bcadd($totalValue, $this->functional($so->so_value, $so->exchange_rate), 2);
            $invoiced = bcadd($invoiced, $this->functional($so->invoiced_amount, $so->exchange_rate), 2);
        }

        $remaining = bcsub($totalValue, $invoiced, 2);
        $pctInvoiced = bccomp($totalValue, '0', 2) > 0 ? bcmul(bcdiv($invoiced, $totalValue, 4), '100', 1) : '0.0';

        return [
            'total_value'  => $totalValue,
            'invoiced'     => $invoiced,
            'remaining'    => $remaining,
            'pct_invoiced' => $pctInvoiced,
            'donut'        => [
                ['category' => 'Invoiced', 'value' => $invoiced],
                ['category' => 'Remaining', 'value' => $remaining],
            ],
        ];
    }

    // ── Top Sales Orders ─────────────────────────────────────────

    private function topOrders(Collection $openOrders): array
    {
        return $openOrders
            ->map(fn ($so) => [
                'id'        => $so->id,
                'so_code'   => $so->so_code,
                'subject'   => $so->subject,
                'partner'   => $so->partner?->name_en,
                'status'    => $so->status,
                'value'     => $this->functional($so->so_value, $so->exchange_rate),
                'invoiced'  => $this->functional($so->invoiced_amount, $so->exchange_rate),
                'remaining' => $this->remaining($so),
            ])
            ->sortByDesc(fn ($row) => (float) $row['value'])
            ->take(10)
            ->values()
            ->all();
    }

    // ── Top Customers ────────────────────────────────────────────

    private function topCustomers(Collection $openOrders): array
    {
        $byPartner = [];

        foreach ($openOrders as $so) {
            $partnerId = $so->partner_id;
            if (! $partnerId) continue;

            if (! isset($byPartner[$partnerId])) {
                $byPartner[$partnerId] = [
                    'partner_id'  => $partnerId,
                    'name'        => $so->partner?->name_en,
                    'code'        => $so->partner?->code,
                    'value'       => '0.00',
                    'remaining'   => '0.00',
                    'order_count' => 0,
                ];
            }

            $byPartner[$partnerId]['value'] = bcadd($byPartner[$partnerId]['value'], $this->functional($so->so_value, $so->exchange_rate), 2);
            $byPartner[$partnerId]['remaining'] = bcadd($byPartner[$partnerId]['remaining'], $this->remaining($so), 2);
            $byPartner[$partnerId]['order_count']++;
        }

        usort($byPartner, fn ($a, $b) => bccomp($b['value'], $a['value'], 2));

        return array_slice(array_values($byPartner), 0, 10);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** This SO's own remaining-to-invoice, in functional currency. */
    private function remaining(SalesOrder $so): string
    {
        return bcsub(
            $this->functional($so->so_value, $so->exchange_rate),
            $this->functional($so->invoiced_amount, $so->exchange_rate),
            2
        );
    }

    /**
     * Native amount → functional currency, via this SO's OWN
     * exchange_rate — the same relationship CustomerBalanceService's
     * nativeAmount() defines in the opposite direction (functional ÷
     * rate = native), confirmed against ContractDashboardService's
     * identical native × rate conversion for Project contract values.
     */
    private function functional(?string $nativeAmount, ?string $exchangeRate): string
    {
        $amount = $nativeAmount ?? '0.00';
        $rate = bccomp((string) ($exchangeRate ?? '0'), '0', 6) > 0 ? (string) $exchangeRate : '1';

        return bcmul($amount, $rate, 2);
    }
}
