<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CashFlowProjectionService
//
//  Financial Institutions > Dashboard > Cash Forecast — Cash Flow
//  Projection (v1, scoped down — confirmed with Mahmoud, Sept 2026).
//
//  CashVero's own CashFlowReportController is 1,131 lines: a full
//  persisted reporting system with saved CashflowReport records,
//  manual per-invoice / per-installment override tools, and a
//  separate contract-drill-down mode. Replicating that exactly is a
//  multi-session build in its own right, not a single feature — so
//  this v1 is DELIBERATELY SCOPED DOWN, flagged explicitly rather
//  than silently dropped (same convention CashVero itself used for
//  its own contract drill-down, per that file's own header):
//    - Cash Inflow  = SUM(CustomerInvoice.amount_outstanding) whose
//      due_date falls in each bucket
//    - Cash Outflow = SUM(SupplierBill.amount_outstanding)
//      + SUM(MtlScheduleLine.remainingAmount())
//      + SUM(LeaseCheque.cheque_amount, status='pending')
//      whose due/payment date falls in each bucket
//    - Accumulated Net Cash = running total starting from the real
//      current cash position (CashStatusDashboardService::
//      openingCashPosition()), forward through each bucket's
//      (inflow − outflow)
//  NOT included in this v1 (real follow-up work, not an oversight):
//    - No manual override/adjustment of individual due amounts
//    - No saved/persisted report snapshots
//    - No per-contract drill-down mode
//    - OD facility revolving balances are NOT projected as cash
//      outflows — they have no fixed "due date" the way a bill or
//      installment does; only invoices, MTL, and Leasing (all of
//      which have real due/payment dates) are projected.
//
//  AUDIT H15 (Sept 2026): invoices and bills were projected at their
//  EGP outstanding amount under their own (e.g. USD) heading, and the
//  starting cash was the EGP book value of each account. Now every
//  figure is in the heading's own currency: open invoices / bills via
//  InvoiceAgingService::nativeOutstanding(), starting cash from each
//  account's native balance (CashStatusDashboardService). Posted
//  documents only; bcmath throughout (Rule 4).
//
//  @layer    Service
//  @depends  CashStatusDashboardService, CustomerInvoice, SupplierBill,
//            MtlScheduleLine, LeaseCheque
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\LeaseCheque;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Models\SupplierBill;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class CashFlowProjectionService
{
    public function __construct(private CashStatusDashboardService $cashStatusService) {}

    public function build(int $companyId, string $startDate, string $endDate, string $interval, array $currencies): array
    {
        $buckets = $this->buildBuckets($startDate, $endDate, $interval);

        $inflowRows  = $this->customerInflowRows($companyId, $startDate, $endDate);
        $outflowRows = $this->outflowRows($companyId, $startDate, $endDate);

        $opening = $this->cashStatusService->openingCashPosition($companyId, $startDate, $currencies);

        $out = [];
        foreach ($currencies as $code) {
            $currencyInflows  = $inflowRows->filter(fn ($r) => $r['currency'] === $code);
            $currencyOutflows = $outflowRows->filter(fn ($r) => $r['currency'] === $code);

            $cashFlowRows = [];
            $running = bcadd((string) ($opening[$code] ?? '0'), '0', 2);
            $netCashRows = [];

            $sumIn = fn ($rows, $bucket) => $rows
                ->filter(fn ($r) => $r['date'] >= $bucket['from'] && $r['date'] <= $bucket['to'])
                ->reduce(fn ($c, $r) => bcadd($c, $r['amount'], 2), '0.00');

            foreach ($buckets as $bucket) {
                $cashIn  = $sumIn($currencyInflows, $bucket);
                $cashOut = $sumIn($currencyOutflows, $bucket);

                $cashFlowRows[] = ['date' => $bucket['from'], 'cash_in' => (float) $cashIn, 'cash_out' => (float) $cashOut];

                $running = bcadd($running, bcsub($cashIn, $cashOut, 2), 2);
                $netCashRows[] = ['date' => $bucket['from'], 'value' => (float) $running];
            }

            $out[$code] = [
                'total_cash_in_out_flow' => $cashFlowRows,
                'accumulated_net_cash'   => $netCashRows,
                'opening_balance'        => bcadd((string) ($opening[$code] ?? '0'), '0', 2),
            ];
        }

        return $out;
    }

    // ── Bucket generation ────────────────────────────────────────

    private function buildBuckets(string $startDate, string $endDate, string $interval): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->endOfDay();
        $buckets = [];

        $step = match ($interval) {
            'daily'   => 1,
            'weekly'  => 7,
            'monthly' => null, // handled separately below
            default   => 7,
        };

        if ($interval === 'monthly') {
            $cursor = $start->copy()->startOfMonth();
            while ($cursor->lte($end)) {
                $bucketEnd = $cursor->copy()->endOfMonth();
                $buckets[] = [
                    'from' => $cursor->toDateString(),
                    'to'   => min($bucketEnd->toDateString(), $end->toDateString()),
                ];
                $cursor->addMonth();
            }
            return $buckets;
        }

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $bucketEnd = $cursor->copy()->addDays($step - 1);
            $buckets[] = [
                'from' => $cursor->toDateString(),
                'to'   => min($bucketEnd->toDateString(), $end->toDateString()),
            ];
            $cursor->addDays($step);
        }

        return $buckets;
    }

    // ── Row collectors (each row: date, amount, currency) ───────

    private function customerInflowRows(int $companyId, string $startDate, string $endDate)
    {
        return CustomerInvoice::where('company_id', $companyId)
            ->where('status', CustomerInvoice::STATUS_POSTED)
            ->where('amount_outstanding', '>', 0)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->with('currency:id,code')
            ->get()
            ->map(fn ($r) => [
                'date'     => $r->due_date->toDateString(),
                'amount'   => InvoiceAgingService::nativeOutstanding($r),
                'currency' => $r->currency?->code,
            ]);
    }

    private function outflowRows(int $companyId, string $startDate, string $endDate)
    {
        $bills = SupplierBill::where('company_id', $companyId)
            ->where('status', SupplierBill::STATUS_POSTED)
            ->where('amount_outstanding', '>', 0)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->with('currency:id,code')
            ->get()
            ->map(fn ($r) => [
                'date'     => $r->due_date->toDateString(),
                'amount'   => InvoiceAgingService::nativeOutstanding($r),
                'currency' => $r->currency?->code,
            ]);

        $mtlFacilityIds = CreditFacility::where('company_id', $companyId)
            ->where('facility_type', 'mtl')->pluck('id', 'id');

        $mtlLines = MtlScheduleLine::where('company_id', $companyId)
            ->whereIn('credit_facility_id', $mtlFacilityIds)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->with('creditFacility.currency:id,code')
            ->get()
            ->filter(fn ($l) => bccomp($l->remainingAmount(), '0', 2) > 0)
            ->map(fn ($l) => [
                'date'     => $l->due_date->toDateString(),
                'amount'   => (string) $l->remainingAmount(),
                'currency' => $l->creditFacility?->currency?->code,
            ]);

        $leaseCheques = LeaseCheque::where('company_id', $companyId)
            ->where('status', 'pending')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('leasingContract')
            ->with('leasingContract.currency:id,code')
            ->get()
            ->map(fn ($c) => [
                'date'     => $c->payment_date->toDateString(),
                'amount'   => (string) $c->cheque_amount,
                'currency' => $c->leasingContract?->currency?->code,
            ]);

        return $bills->concat($mtlLines)->concat($leaseCheques);
    }
}
