<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderStockBalanceService
//
//  Manufacturing Variance Closing Design v1.0, Section 6. Owns exactly
//  one thing: the job_order_stock_balances table — a single Job
//  Order's own quantity and cost, kept separate from
//  ProductStockBalance's company-wide pooled WAC. Deliberately narrow
//  and separate from StockLedgerService, at Mahmoud's request — one
//  small, easy-to-audit file for Job Order's own cost segment, rather
//  than adding more surface area to the shared Stock Ledger.
//
//  This does NOT replace StockLedgerService for anything physical —
//  quantity, location, and the stock_movements audit trail are still
//  written exclusively by StockLedgerService, exactly as before (see
//  that class's job_order_id handling in receive()/reverseReceipt()).
//  This service only tracks the COST side of a Job Order's own
//  output, called alongside — never instead of — the Stock Ledger.
//
//  MFG-003 fix (confirmed with Mahmoud, 2026-09) — totalsByProduct()
//  and breakdownForProduct() are the read-only counterpart: the Stock
//  Balance page (StockLedgerService::paginateBalances()) only ever
//  showed ProductStockBalance's pooled figures, which by design never
//  include a Job Order's own segment — so it disagreed with the
//  Accounting Dashboard (which sums stock_movements directly and does
//  see Job Order receipts) by exactly the value of undelivered Job
//  Order output. These two methods let that page show the missing
//  piece explicitly, rather than silently reconciling the two numbers
//  into one blended figure that would hide which part is general
//  stock and which part is reserved for a specific customer.
//
//  Same locking discipline as ProductStockBalance: every row is
//  locked with lockForUpdate() inside the caller's own DB transaction
//  before being read or written.
//
//  @layer    Service
//  @depends  JobOrderStockBalance, JobOrder, Product
// ══════════════════════════════════════════════════════════════════

use App\Models\JobOrder;
use App\Models\JobOrderStockBalance;
use App\Models\Product;

class JobOrderStockBalanceService
{
    // Adds this job's completed output into its own segment. A Job
    // Order normally completes ONCE, so this is usually a straight
    // create — but written as an add, not a set, so it stays correct
    // even if a job is ever completed more than once (e.g. a partial
    // completion flow added later).
    public function receive(JobOrder $jobOrder, Product $product, string $quantity, string $unitCost, ?string $totalValue = null): JobOrderStockBalance
    {
        $totalValue ??= bcmul($quantity, $unitCost, 2);

        $balance = $this->lock($jobOrder, $product);

        $newQty   = bcadd($balance->quantity_on_hand, $quantity, 4);
        $newValue = bcadd($balance->total_value, $totalValue, 2);
        $newUnit  = bccomp($newQty, '0', 4) > 0 ? bcdiv($newValue, $newQty, 4) : '0.0000';

        $balance->update([
            'quantity_on_hand' => $newQty,
            'unit_cost'        => $newUnit,
            'total_value'      => $newValue,
        ]);

        return $balance->fresh();
    }

    // Mirror of receive() — undoes a completion being reopened to
    // draft (JobOrderService::setToDraft()). Guards against removing
    // more than what this segment actually holds, same reasoning as
    // StockLedgerService::reverseReceipt()'s own guard.
    public function reverse(JobOrder $jobOrder, Product $product, string $quantity, string $totalValue): JobOrderStockBalance
    {
        $balance = $this->lock($jobOrder, $product);

        if (bccomp($balance->quantity_on_hand, $quantity, 4) < 0) {
            throw new \DomainException(
                "Cannot reopen this Job Order — its Finished Goods segment no longer holds this much of {$product->name_en}."
            );
        }

        $newQty   = bcsub($balance->quantity_on_hand, $quantity, 4);
        $newValue = bcsub($balance->total_value, $totalValue, 2);
        if (bccomp($newValue, '0', 2) < 0) {
            $newValue = '0.00'; // guards against rounding drift, same as ProductStockBalance's own reversal
        }
        $newUnit = bccomp($newQty, '0', 4) > 0 ? bcdiv($newValue, $newQty, 4) : '0.0000';

        $balance->update([
            'quantity_on_hand' => $newQty,
            'unit_cost'        => $newUnit,
            'total_value'      => $newValue,
        ]);

        return $balance->fresh();
    }

    // Mirror of receive() — decrements this job's own segment when its
    // output is delivered to the customer. Cost per unit is NOT
    // recalculated here, same principle as ProductStockBalance's own
    // issue(): only receipts (which for a Job Order means only
    // complete(), which normally runs once) ever change the unit
    // cost.
    public function issue(JobOrder $jobOrder, Product $product, string $quantity): JobOrderStockBalance
    {
        $balance = $this->lock($jobOrder, $product);

        if (bccomp($balance->quantity_on_hand, $quantity, 4) < 0) {
            throw new \DomainException(
                "Job Order {$jobOrder->job_code} only has {$balance->quantity_on_hand} of {$product->name_en} left in its own Finished Goods segment — the remaining quantity must be delivered as ordinary stock, on a separate line."
            );
        }

        $issueValue = bcmul($quantity, $balance->unit_cost, 2);

        $newQty   = bcsub($balance->quantity_on_hand, $quantity, 4);
        $newValue = bcsub($balance->total_value, $issueValue, 2);
        if (bccomp($newValue, '0', 2) < 0) {
            $newValue = '0.00';
        }

        $balance->update([
            'quantity_on_hand' => $newQty,
            'total_value'      => $newValue,
        ]);

        return $balance->fresh();
    }

    private function lock(JobOrder $jobOrder, Product $product): JobOrderStockBalance
    {
        JobOrderStockBalance::firstOrCreate(
            ['company_id' => $jobOrder->company_id, 'job_order_id' => $jobOrder->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 0, 'unit_cost' => 0, 'total_value' => 0]
        );

        return JobOrderStockBalance::where('company_id', $jobOrder->company_id)
            ->where('job_order_id', $jobOrder->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();
    }

    // ── Read: Stock Balance page (MFG-003 fix) ──────────────────────

    /**
     * Every product that currently has stock sitting in one or more
     * Job Orders' own segments, summed across all of them —
     * [product_id => ['quantity' => '...', 'value' => '...']]. Used
     * by the Stock Balance page to show what's missing from
     * ProductStockBalance's pooled figure, and to correct the "Total
     * Inventory Value" KPI so it reconciles with the Accounting
     * Dashboard / GL again.
     */
    public function totalsByProduct(int $companyId): array
    {
        return JobOrderStockBalance::where('company_id', $companyId)
            ->where('quantity_on_hand', '>', 0)
            ->selectRaw('product_id, SUM(quantity_on_hand) as quantity, SUM(total_value) as value')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id')
            ->map(fn ($row) => ['quantity' => (string) $row->quantity, 'value' => (string) $row->value])
            ->toArray();
    }

    /**
     * Which specific Job Orders hold this product's segment stock,
     * for the Stock Balance page's row-expand detail — mirrors
     * StockLedgerService::locationBreakdown()'s shape/purpose, just
     * for the second pool instead of the first.
     */
    public function breakdownForProduct(int $companyId, int $productId): \Illuminate\Support\Collection
    {
        return JobOrderStockBalance::with('jobOrder:id,job_code')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('quantity_on_hand', '>', 0)
            ->get()
            ->map(fn ($balance) => [
                'job_order_id'     => $balance->job_order_id,
                'job_code'         => $balance->jobOrder->job_code,
                'quantity_on_hand' => (string) $balance->quantity_on_hand,
                'total_value'      => (string) $balance->total_value,
            ]);
    }
}
