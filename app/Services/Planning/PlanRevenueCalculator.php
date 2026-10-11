<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanRevenueCalculator
//
//  Step 1 of the calculation engine — bcmath port of CFO Tools'
//  calcRevenue.js, adapted to Vero CFO's relational plan_sales_lines
//  grid (one row per product per month) instead of a JSON blob.
//
//  SIMPLIFICATION FLAGGED, NOT HIDDEN — VAT and WHT are reported as
//  MEMO figures only, not fed into AR / cash collections / the
//  Balance Sheet. Reasoning: VAT is a real liability (VAT Payable)
//  that would need its own running balance, and WHT is a real asset
//  (WHT Receivable, offsetting Corporate Income Tax) — modeling both
//  properly the way CFO Tools does would need a matching liability/
//  asset on the Balance Sheet plus input-VAT tracking on the COGS
//  side (not built in PlanCogsCalculator's Phase 1 scope either).
//  Doing it by half — deducting VAT/WHT from cash without a matching
//  BS line — silently breaks the Assets = Liabilities + Equity tie-
//  out. Rather than ship a Balance Sheet that doesn't balance, AR and
//  cash collections here run on NET revenue only; vat_estimate_by_month
//  and wht_estimate_by_month are still computed and returned for the
//  user's reference (e.g. "VAT you'll need to remit"), clearly
//  separate from the figures that actually drive the three
//  statements. A later phase can wire these in properly once VAT
//  Payable / WHT Receivable exist on the plan's own mini-Balance-Sheet.
//
//  For each plan_product, each month:
//    revenue         = price × volume  (net — what P&L, AR, and cash
//                       collections are all based on)
//    local / export  = revenue × local_pct / export_pct
//    cash collected  = revenue, spread across plan_collection_tranches
//                       (pct / days_after_sale), per market
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, PlanProduct, PlanSalesLine, PlanCollectionTranche
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;

class PlanRevenueCalculator
{
    /**
     * Returns:
     *  [
     *    'revenue_by_month'          => [monthIndex => 'net revenue, all products'],
     *    'ar_balance_by_month'       => [monthIndex => 'running AR balance, net revenue basis'],
     *    'receipts_by_month'         => [monthIndex => 'cash actually received'],
     *    'vat_estimate_by_month'     => [monthIndex => 'memo only — see header'],
     *    'wht_estimate_by_month'     => [monthIndex => 'memo only — see header'],
     *    'by_product'                => [planProductId => ['name'=>..,'revenue_by_month'=>[...],'volume_by_month'=>[...]]],
     *  ]
     * All money values are bcmath strings, 2dp.
     */
    public function calculate(Plan $plan): array
    {
        $months = $plan->totalMonths();
        $zero   = array_fill(0, $months, '0.00');

        $revenueByMonth    = $zero;
        $invoicedByMonth   = $zero;
        $clearedByMonth    = $zero;
        $receiptsByMonth   = $zero;
        $vatEstimateByMonth = $zero;
        $whtEstimateByMonth = $zero;
        $byProduct = [];

        foreach ($plan->products as $product) {
            $tranchesByMarket = $product->collectionTranches->groupBy('market');
            $vatRate = bcdiv((string) $product->vat_rate, '100', 6);
            $whtRate = bcdiv((string) $product->wht_rate, '100', 6);

            $productRevenue = $zero;
            $productVolume  = $zero;
            $salesLines = $product->salesLines->keyBy('period_month');

            foreach (range(0, $months - 1) as $m) {
                $line = $salesLines->get($m);
                if (! $line) {
                    continue;
                }

                $revenue = $line->grossRevenue(); // price × volume, net
                if (bccomp($revenue, '0', 2) === 0) {
                    continue;
                }

                $productRevenue[$m] = $revenue;
                $productVolume[$m]  = (string) $line->volume;
                $revenueByMonth[$m] = bcadd($revenueByMonth[$m], $revenue, 2);
                $invoicedByMonth[$m] = bcadd($invoicedByMonth[$m], $revenue, 2);

                $localPct  = bcdiv((string) $line->local_pct, '100', 6);
                $exportPct = bcdiv((string) $line->export_pct, '100', 6);
                $localRevenue  = bcmul($revenue, $localPct, 2);
                $exportRevenue = bcmul($revenue, $exportPct, 2);

                // Memo only — see class header.
                $vatEstimateByMonth[$m] = bcadd($vatEstimateByMonth[$m], bcmul($localRevenue, $vatRate, 2), 2);
                $whtEstimateByMonth[$m] = bcadd($whtEstimateByMonth[$m], bcmul($revenue, $whtRate, 2), 2);

                $this->applyTranches($tranchesByMarket->get('local', collect()), $m, $localRevenue, $receiptsByMonth, $clearedByMonth, $months);
                $this->applyTranches($tranchesByMarket->get('export', collect()), $m, $exportRevenue, $receiptsByMonth, $clearedByMonth, $months);
            }

            $byProduct[$product->id] = [
                'name'             => $product->name,
                'revenue_by_month' => $productRevenue,
                'volume_by_month'  => $productVolume,
            ];
        }

        return [
            'revenue_by_month'      => $revenueByMonth,
            'ar_balance_by_month'   => $this->runningBalance($invoicedByMonth, $clearedByMonth),
            'receipts_by_month'     => $receiptsByMonth,
            'vat_estimate_by_month' => $vatEstimateByMonth,
            'wht_estimate_by_month' => $whtEstimateByMonth,
            'by_product'            => $byProduct,
        ];
    }

    private function applyTranches($tranches, int $month, string $amount, array &$receiptsByMonth, array &$clearedByMonth, int $totalMonths): void
    {
        if (bccomp($amount, '0', 2) === 0 || $tranches->isEmpty()) {
            return;
        }

        foreach ($tranches as $tranche) {
            $pct = bcdiv((string) $tranche->pct, '100', 6);
            if (bccomp($pct, '0', 6) === 0) {
                continue;
            }

            $targetMonth = $month + (int) round($tranche->days_after_sale / 30);
            if ($targetMonth >= $totalMonths) {
                continue;
            }

            $tranche_amount = bcmul($amount, $pct, 2);
            $receiptsByMonth[$targetMonth] = bcadd($receiptsByMonth[$targetMonth], $tranche_amount, 2);
            $clearedByMonth[$targetMonth]  = bcadd($clearedByMonth[$targetMonth], $tranche_amount, 2);
        }
    }

    /** Cumulative invoiced minus cumulative cleared, floored at 0 per month. */
    private function runningBalance(array $invoiced, array $cleared): array
    {
        $balance = [];
        $cumInv = '0.00';
        $cumClr = '0.00';

        foreach ($invoiced as $m => $inv) {
            $cumInv = bcadd($cumInv, $inv, 2);
            $cumClr = bcadd($cumClr, $cleared[$m], 2);
            $diff = bcsub($cumInv, $cumClr, 2);
            $balance[$m] = bccomp($diff, '0', 2) > 0 ? $diff : '0.00';
        }

        return $balance;
    }
}
