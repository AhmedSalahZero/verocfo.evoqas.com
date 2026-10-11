<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  VarianceClosingService
//
//  Manufacturing Variance Closing Design v1.0, Section 1/Step 3 — the
//  monthly closing engine.
//
//  computeProposal() is READ-ONLY: calculates the full split, posts
//  nothing. post() takes that same calculation and actually posts it
//  — one combined journal entry, plus a ManufacturingVarianceClosing
//  header record so a period can never be closed twice.
//
//  THE TWO-LEVEL PUSH-DOWN (Section 1.2/1.3):
//
//  Level 1 — pool/labor total → individual orders, by hours used.
//  ManufacturingVariancePeriod (Step 3 piece 1) already holds the
//  exact labor variance (one company-wide figure — Option B, Section
//  1.5) and each Overhead Pool's own variance for the period. This
//  sums how many hours EVERY Production Order and Job Order logged
//  against each one that period (from their own cost entries —
//  entry_date defines "this period", regardless of the order's
//  current status or how long it's been open) and splits the
//  variance proportionally: (this order's hours ÷ total hours) × the
//  pool's variance. The labor portion and EACH pool's portion are
//  kept separate all the way through — never blended into one number
//  — because they clear from different GL accounts (514 vs. 515).
//
//  Level 2 — each order's share → where it actually lands:
//    - Still IN PROGRESS → its share (labor + every pool it used)
//      moves straight into WIP (Section 2, confirmed: no different
//      treatment for open orders, same push-down, different
//      destination).
//    - COMPLETED Production Orders → their shares are summed PER
//      PRODUCT (several orders can complete the same product in one
//      month), keeping labor and each pool separate, then split
//      between COGS and Finished Goods using Sold-vs-Ending (below).
//    - COMPLETED Job Orders → NOT aggregated with anything else (each
//      job is its own segment, Section 6) — same Sold-vs-Ending
//      split, scoped only to that job's own segment.
//
//  SOLD-VS-ENDING, THE ACTUAL FORMULA USED (a direct, simpler
//  equivalent of Section 1.3's "Available = Opening + Produced;
//  Sold = Available − Ending"): rather than reconstructing a
//  historical opening balance, this reads Sold directly from the
//  period's own real TYPE_ISSUE movements, and Ending from the
//  current balance — Available is then just Sold + Ending. Same
//  proxy idea, same result, no historical-balance reconstruction
//  needed. The SAME ratio is applied to the labor component and each
//  pool's component separately (not just to a blended total), so
//  post() can credit exactly the right variance account for each
//  slice.
//
//  POSTING SHAPE — for a positive (unfavorable) share: Dr destination
//  / Cr the variance account it came from (514 or 515) — moving that
//  cost OUT of the standalone variance line and INTO WIP/COGS/FG,
//  where absorption costing says it belongs. A negative (favorable)
//  share posts the same lines with debit/credit flipped. Products'
//  and Job Orders' OWN recorded balances (ProductStockBalance /
//  JobOrderStockBalance) are adjusted by their Finished-Goods/
//  remaining-segment portion too, so a later sale prices correctly
//  against the corrected cost — not just a GL-only plug with a stale
//  subledger underneath it.
//
//  MATERIAL VARIANCE (audit M-14): completing an order relieves WIP at
//  STANDARD, so the order's material variance (actual material consumed,
//  net of reversals, minus standard material) stays behind in WIP.
//  Every completed, not-yet-cleared order up to the period end has that
//  residue moved out here — Dr COGS/Finished Goods, Cr WIP (flipped if
//  favorable) — through the same per-product / per-job Sold-vs-Ending
//  split as labor and overhead. Each order is stamped with the closing
//  that cleared it, so it is never cleared twice.
//
//  CONTROLS (audit M-15): an override that changes the calculated split
//  needs a written reason and must stay between 0 and the row's total.
//  A posted closing can be reversed (latest period first) with a reason.
//
//  KNOWN LIMITATION (flagged, not silently glossed over): a WIP
//  adjustment corrects the GL's own WIP balance, but does not create
//  a new ProductionOrderCostEntry/JobOrderCostEntry row, so that
//  order's own wipBalance()/variance() reporting will not reflect
//  this adjustment until/unless a future piece adds that. Only the
//  GL figure is guaranteed correct today.
//
//  @layer    Service
//  @depends  ManufacturingVariancePeriod, ManufacturingVarianceClosing,
//            ProductionOrder, JobOrder, ProductionOrderCostEntry,
//            JobOrderCostEntry, Product, ProductStockBalance,
//            JobOrderStockBalance, StockMovement, OverheadPool,
//            ChartOfAccount, JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JobOrder;
use App\Models\JobOrderCostEntry;
use App\Models\JobOrderStockBalance;
use App\Models\ManufacturingVarianceClosing;
use App\Models\ManufacturingVariancePeriod;
use App\Models\OverheadPool;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCostEntry;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VarianceClosingService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
    ) {}

    public function computeProposal(int $companyId, int $periodYear, int $periodMonth): array
    {
        $varianceRows = ManufacturingVariancePeriod::where('company_id', $companyId)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->get();

        $materialOrders = $this->materialVarianceCandidates($companyId, $periodYear, $periodMonth);

        if ($varianceRows->isEmpty() && empty($materialOrders)) {
            throw new \DomainException(
                "No Payroll Run has been posted for {$periodMonth}/{$periodYear} yet — variance can't be calculated until it has."
            );
        }

        $laborVarianceRow = $varianceRows->firstWhere('variance_type', ManufacturingVariancePeriod::TYPE_LABOR);
        $overheadVarianceRows = $varianceRows->where('variance_type', ManufacturingVariancePeriod::TYPE_OVERHEAD);

        // ── Level 1a — labor: one combined figure (Option B) ────────
        $productionLaborEntries = ProductionOrderCostEntry::whereHas('productionOrder', fn ($q) => $q->where('company_id', $companyId))
            ->where('entry_type', ProductionOrderCostEntry::TYPE_LABOR)
            ->whereYear('entry_date', $periodYear)->whereMonth('entry_date', $periodMonth)
            ->get();
        $jobLaborEntries = JobOrderCostEntry::whereHas('jobOrder', fn ($q) => $q->where('company_id', $companyId))
            ->where('entry_type', JobOrderCostEntry::TYPE_LABOR)
            ->whereYear('entry_date', $periodYear)->whereMonth('entry_date', $periodMonth)
            ->get();

        $totalLaborHours = bcadd((string) $productionLaborEntries->sum('base_quantity'), (string) $jobLaborEntries->sum('base_quantity'), 4);

        // Per order: ['labor' => amount, 'overhead' => [poolId => amount]] — kept separate throughout.
        $orderBreakdowns = [];

        if ($laborVarianceRow && bccomp($totalLaborHours, '0', 4) > 0) {
            foreach ($this->hoursByOrder($productionLaborEntries, 'production_order_id', 'production') as $key => $hours) {
                $orderBreakdowns[$key]['labor'] = bcmul(bcdiv($hours, $totalLaborHours, 8), (string) $laborVarianceRow->amount, 2);
            }
            foreach ($this->hoursByOrder($jobLaborEntries, 'job_order_id', 'job') as $key => $hours) {
                $orderBreakdowns[$key]['labor'] = bcmul(bcdiv($hours, $totalLaborHours, 8), (string) $laborVarianceRow->amount, 2);
            }
        }

        // ── Level 1b — overhead: genuinely per pool ──────────────────
        $overheadByPool = [];
        foreach ($overheadVarianceRows as $row) {
            $poolId = $row->overhead_pool_id;

            $productionEntries = ProductionOrderCostEntry::whereHas('productionOrder', fn ($q) => $q->where('company_id', $companyId))
                ->where('entry_type', ProductionOrderCostEntry::TYPE_OVERHEAD)
                ->where('overhead_pool_id', $poolId)
                ->whereYear('entry_date', $periodYear)->whereMonth('entry_date', $periodMonth)
                ->get();
            $jobEntries = JobOrderCostEntry::whereHas('jobOrder', fn ($q) => $q->where('company_id', $companyId))
                ->where('entry_type', JobOrderCostEntry::TYPE_OVERHEAD)
                ->where('overhead_pool_id', $poolId)
                ->whereYear('entry_date', $periodYear)->whereMonth('entry_date', $periodMonth)
                ->get();

            $totalPoolHours = bcadd((string) $productionEntries->sum('base_quantity'), (string) $jobEntries->sum('base_quantity'), 4);

            $overheadByPool[$poolId] = [
                'pool'        => $row->overheadPool,
                'variance'    => (string) $row->amount,
                'total_hours' => $totalPoolHours,
            ];

            if (bccomp($totalPoolHours, '0', 4) <= 0) {
                continue;
            }

            foreach ($this->hoursByOrder($productionEntries, 'production_order_id', 'production') as $key => $hours) {
                $orderBreakdowns[$key]['overhead'][$poolId] = bcmul(bcdiv($hours, $totalPoolHours, 8), (string) $row->amount, 2);
            }
            foreach ($this->hoursByOrder($jobEntries, 'job_order_id', 'job') as $key => $hours) {
                $orderBreakdowns[$key]['overhead'][$poolId] = bcmul(bcdiv($hours, $totalPoolHours, 8), (string) $row->amount, 2);
            }
        }

        // ── Level 2 — resolve each order: still open → WIP; completed → aggregate ──
        $wipAdjustments = [];
        $productBreakdowns = []; // product_id => ['labor' => running, 'overhead' => [poolId => running]]
        $completedJobBreakdowns = []; // job_order_id => ['job' => JobOrder, 'labor' => .., 'overhead' => [..]]

        foreach ($orderBreakdowns as $key => $breakdown) {
            [$type, $id] = explode(':', $key, 2);
            $totalShare = $this->sumBreakdown($breakdown);

            if ($type === 'production') {
                $order = ProductionOrder::find($id);
                if (! $order) {
                    continue;
                }
                if ($order->status === ProductionOrder::STATUS_IN_PROGRESS) {
                    $wipAdjustments[] = ['type' => 'production_order', 'order' => $order, 'breakdown' => $breakdown, 'amount' => $totalShare];
                } elseif ($order->status === ProductionOrder::STATUS_COMPLETED) {
                    if (! isset($productBreakdowns[$order->product_id])) {
                        $productBreakdowns[$order->product_id] = ['labor' => '0.00', 'overhead' => []];
                    }
                    $this->mergeBreakdown($productBreakdowns[$order->product_id], $breakdown);
                }
            } else {
                $job = JobOrder::with('stockBalance')->find($id);
                if (! $job) {
                    continue;
                }
                if ($job->status === JobOrder::STATUS_IN_PROGRESS) {
                    $wipAdjustments[] = ['type' => 'job_order', 'order' => $job, 'breakdown' => $breakdown, 'amount' => $totalShare];
                } elseif ($job->status === JobOrder::STATUS_COMPLETED) {
                    if (! isset($completedJobBreakdowns[$job->id])) {
                        $completedJobBreakdowns[$job->id] = ['job' => $job, 'labor' => '0.00', 'overhead' => []];
                    }
                    $this->mergeBreakdown($completedJobBreakdowns[$job->id], $breakdown);
                }
            }
        }

        // ── Material variance of completed orders (audit M-14) ──
        $materialTotal = '0.00';
        foreach ($materialOrders as $m) {
            $materialTotal = bcadd($materialTotal, $m['amount'], 2);
            if (bccomp($m['amount'], '0', 2) == 0) {
                continue;
            }
            if ($m['type'] === 'production') {
                $pid = $m['order']->product_id;
                $productBreakdowns[$pid] ??= ['labor' => '0.00', 'overhead' => []];
                $productBreakdowns[$pid]['material'] = bcadd($productBreakdowns[$pid]['material'] ?? '0.00', $m['amount'], 2);
            } else {
                $job = JobOrder::with('stockBalance')->find($m['id']);
                $completedJobBreakdowns[$job->id] ??= ['job' => $job, 'labor' => '0.00', 'overhead' => []];
                $completedJobBreakdowns[$job->id]['material'] = bcadd($completedJobBreakdowns[$job->id]['material'] ?? '0.00', $m['amount'], 2);
            }
        }

        // ── Products: split the aggregated breakdown by Sold vs Ending ──
        $productSplits = [];
        foreach ($productBreakdowns as $productId => $breakdown) {
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            $shareAmount = $this->sumBreakdown($breakdown);

            $soldQty = (string) StockMovement::where('company_id', $companyId)
                ->where('product_id', $productId)
                ->where('movement_type', StockMovement::TYPE_ISSUE)
                ->whereNull('job_order_id')
                ->whereYear('movement_date', $periodYear)->whereMonth('movement_date', $periodMonth)
                ->sum('quantity');

            $endingQty = (string) (ProductStockBalance::where('company_id', $companyId)->where('product_id', $productId)->value('quantity_on_hand') ?? '0');

            [$cogsRatio, $fgRatio] = $this->soldVsEndingRatios($soldQty, $endingQty);

            $productSplits[$productId] = [
                'product'        => $product,
                'variance_share' => $shareAmount,
                'sold_qty'       => $soldQty,
                'ending_qty'     => $endingQty,
                'cogs_amount'    => $this->applyRatio($shareAmount, $cogsRatio),
                'fg_amount'      => $this->applyRatio($shareAmount, $fgRatio),
                'cogs_breakdown' => $this->scaleBreakdown($breakdown, $cogsRatio),
                'fg_breakdown'   => $this->scaleBreakdown($breakdown, $fgRatio),
            ];
        }

        // ── Completed Job Orders: split by THAT job's own segment's Sold vs Ending — never aggregated with anything else ──
        $jobOrderSplits = [];
        foreach ($completedJobBreakdowns as $jobId => $data) {
            $job = $data['job'];
            $breakdown = ['labor' => $data['labor'] ?? '0.00', 'overhead' => $data['overhead'] ?? [], 'material' => $data['material'] ?? '0.00'];
            $shareAmount = $this->sumBreakdown($breakdown);

            $soldQty = (string) StockMovement::where('company_id', $companyId)
                ->where('job_order_id', $jobId)
                ->where('movement_type', StockMovement::TYPE_ISSUE)
                ->whereYear('movement_date', $periodYear)->whereMonth('movement_date', $periodMonth)
                ->sum('quantity');

            $endingQty = (string) ($job->stockBalance->quantity_on_hand ?? '0');

            [$cogsRatio, $remainingRatio] = $this->soldVsEndingRatios($soldQty, $endingQty);

            $jobOrderSplits[$jobId] = [
                'job_order'                => $job,
                'variance_share'           => $shareAmount,
                'sold_qty'                 => $soldQty,
                'ending_qty'               => $endingQty,
                'cogs_amount'              => $this->applyRatio($shareAmount, $cogsRatio),
                'remaining_segment_amount' => $this->applyRatio($shareAmount, $remainingRatio),
                'cogs_breakdown'           => $this->scaleBreakdown($breakdown, $cogsRatio),
                'remaining_breakdown'      => $this->scaleBreakdown($breakdown, $remainingRatio),
            ];
        }

        return [
            'period_year'               => $periodYear,
            'period_month'              => $periodMonth,
            'labor_variance_total'      => $laborVarianceRow ? (string) $laborVarianceRow->amount : '0.00',
            'total_labor_hours'         => $totalLaborHours,
            'overhead_variance_by_pool' => $overheadByPool,
            'wip_adjustments'           => $wipAdjustments,
            'product_splits'            => $productSplits,
            'job_order_splits'          => $jobOrderSplits,
            'material_variance_total'   => $materialTotal,
            'material_orders'           => array_map(fn ($m) => [
                'type' => $m['type'], 'id' => $m['id'], 'code' => $m['code'], 'amount' => $m['amount'],
            ], $materialOrders),
        ];
    }

    /**
     * Completed, not-yet-cleared orders up to the period end, with their
     * material variance (actual net of reversals − standard material).
     *
     * @return array<int, array{type:string,id:int,code:string,amount:string,order:mixed}>
     */
    private function materialVarianceCandidates(int $companyId, int $periodYear, int $periodMonth): array
    {
        $end = \Carbon\Carbon::create($periodYear, $periodMonth, 1)->endOfMonth()->toDateString();
        $result = [];

        $productionOrders = ProductionOrder::where('company_id', $companyId)
            ->where('status', ProductionOrder::STATUS_COMPLETED)
            ->whereNull('material_variance_closing_id')
            ->whereDate('actual_completion_date', '<=', $end)
            ->get();

        foreach ($productionOrders as $order) {
            $actual = bcsub(
                (string) $order->stockMovements()->where('movement_type', StockMovement::TYPE_PRODUCTION_CONSUMPTION)->sum('wac_total_value'),
                (string) $order->stockMovements()->where('movement_type', StockMovement::TYPE_ISSUE_REVERSAL)->sum('wac_total_value'),
                2
            );
            $standard = $order->standard_material_cost_total !== null
                ? (string) $order->standard_material_cost_total
                : bcmul((string) $order->standard_material_cost_per_unit, (string) $order->quantity_to_produce, 2);

            $result[] = ['type' => 'production', 'id' => $order->id, 'code' => $order->order_code, 'amount' => bcsub($actual, $standard, 2), 'order' => $order];
        }

        $jobOrders = JobOrder::where('company_id', $companyId)
            ->where('status', JobOrder::STATUS_COMPLETED)
            ->whereNull('material_variance_closing_id')
            ->whereDate('actual_completion_date', '<=', $end)
            ->get();

        foreach ($jobOrders as $job) {
            $actual = bcsub(
                (string) $job->stockMovements()->where('movement_type', StockMovement::TYPE_JOB_CONSUMPTION)->sum('wac_total_value'),
                (string) $job->stockMovements()->where('movement_type', StockMovement::TYPE_ISSUE_REVERSAL)->sum('wac_total_value'),
                2
            );
            $standard = $job->estimated_material_cost_total !== null
                ? (string) $job->estimated_material_cost_total
                : bcmul((string) $job->estimated_material_cost_per_unit, (string) $job->quantity_to_produce, 2);

            $result[] = ['type' => 'job', 'id' => $job->id, 'code' => $job->job_code, 'amount' => bcsub($actual, $standard, 2), 'order' => $job];
        }

        return $result;
    }

    // ── Posting — takes the SAME shape computeProposal() returns and
    // actually posts it. Re-runs computeProposal() itself rather than
    // trusting a caller-supplied array verbatim, so what gets posted
    // is always freshly calculated against the current data at the
    // moment of posting, not a possibly-stale snapshot from whenever
    // the review screen was first opened. (A future override screen
    // can layer on top of this without changing this method's shape.)
    // $overrides (optional) — ['products' => [productId => overriddenFgAmount], 'job_orders' => [jobId => overriddenRemainingAmount]].
    // Section 1.4's confirmed "review and override before posting":
    // a person can move the split point between COGS and Finished
    // Goods/remaining-segment for any row. The TOTAL variance being
    // cleared for that row never changes — only how it's divided —
    // so 514/515 always clear correctly regardless of what's
    // overridden. Overriding the underlying hours-based allocation
    // itself is a further layer, not supported here — flagged, not
    // silently assumed away.
    public function post(int $companyId, int $periodYear, int $periodMonth, User $actor, array $overrides = [], ?string $overrideReason = null): ManufacturingVarianceClosing
    {
        if (ManufacturingVarianceClosing::where('company_id', $companyId)->where('period_year', $periodYear)->where('period_month', $periodMonth)->whereNull('reversed_at')->exists()) {
            throw new \DomainException("The manufacturing variance for {$periodMonth}/{$periodYear} has already been closed — it cannot be closed twice.");
        }

        $proposal = $this->computeProposal($companyId, $periodYear, $periodMonth);
        $this->assertOverridesAllowed($proposal, $overrides, $overrideReason);
        $proposal = $this->applyOverrides($proposal, $overrides);

        return DB::transaction(function () use ($companyId, $periodYear, $periodMonth, $proposal, $actor, $overrideReason) {
            $wipAccount              = $this->resolveSystemAccount($companyId, '135002', 'Manufacturing WIP');
            $laborVarianceAccount    = $this->resolveSystemAccount($companyId, '514', 'Manufacturing Labor Variance');
            $overheadVarianceAccount = $this->resolveSystemAccount($companyId, '515', 'Manufacturing Overhead Variance');

            // RPT-003 fix (8 Sep 2026) — computed here (rather than
            // only inside the `if (! empty($lines))` block below)
            // because adjustProductBalance() now also needs it, to
            // date the stock movement it writes the same day as the
            // GL entry that causes it.
            $entryDate = \Carbon\Carbon::create($periodYear, $periodMonth, 1)->endOfMonth()->toDateString();

            $lines = [];

            // ── WIP adjustments — still-open orders ──
            foreach ($proposal['wip_adjustments'] as $adj) {
                $label = $adj['type'] === 'production_order'
                    ? "Production Order {$adj['order']->order_code}"
                    : "Job Order {$adj['order']->job_code}";

                $this->addBreakdownLines($lines, $wipAccount->id, $laborVarianceAccount->id, $overheadVarianceAccount->id, $adj['breakdown'], "Variance closing {$periodMonth}/{$periodYear} — {$label} — WIP");
            }

            // ── Products — COGS / Finished Goods split ──
            foreach ($proposal['product_splits'] as $productId => $split) {
                $product = $split['product'];

                $this->addBreakdownLines($lines, $product->cogs_account_id, $laborVarianceAccount->id, $overheadVarianceAccount->id, $split['cogs_breakdown'], "Variance closing {$periodMonth}/{$periodYear} — {$product->name_en} — COGS", $wipAccount->id);
                $this->addBreakdownLines($lines, $product->inventory_account_id, $laborVarianceAccount->id, $overheadVarianceAccount->id, $split['fg_breakdown'], "Variance closing {$periodMonth}/{$periodYear} — {$product->name_en} — Finished Goods", $wipAccount->id);

                // Keep the subledger in step with the GL — a later
                // sale must price against the corrected cost, not a
                // stale one sitting under a GL-only plug.
                $this->adjustProductBalance($companyId, $productId, $split['fg_amount'], $entryDate, $periodMonth, $periodYear, $actor);
            }

            // ── Completed Job Orders — COGS / remaining segment split ──
            foreach ($proposal['job_order_splits'] as $jobId => $split) {
                $job = $split['job_order'];
                $product = Product::findOrFail($job->product_id);

                $this->addBreakdownLines($lines, $product->cogs_account_id, $laborVarianceAccount->id, $overheadVarianceAccount->id, $split['cogs_breakdown'], "Variance closing {$periodMonth}/{$periodYear} — Job Order {$job->job_code} — COGS", $wipAccount->id);
                $this->addBreakdownLines($lines, $product->inventory_account_id, $laborVarianceAccount->id, $overheadVarianceAccount->id, $split['remaining_breakdown'], "Variance closing {$periodMonth}/{$periodYear} — Job Order {$job->job_code} — remaining segment", $wipAccount->id);

                $this->adjustJobOrderBalance($job, $split['remaining_segment_amount'], $entryDate, $periodMonth, $periodYear, $actor);
            }

            $journalEntry = null;
            if (! empty($lines)) {
                $journalEntry = $this->journalEntryService->post(
                    companyId: $companyId,
                    entryDate: $entryDate,
                    lines: $lines,
                    sourceType: 'manufacturing_variance_closing',
                    sourceId: null,
                    narration: "Manufacturing Variance Closing — {$periodMonth}/{$periodYear}",
                    actor: $actor,
                );
            }


            $closing = ManufacturingVarianceClosing::create([
                'company_id'        => $companyId,
                'period_year'       => $periodYear,
                'period_month'      => $periodMonth,
                'journal_entry_id'  => $journalEntry?->id,
                'proposal_snapshot' => $this->serializeProposal($proposal),
                'posted_by'         => $actor->id,
                'posted_at'         => now(),
                'override_reason'   => $overrideReason ? trim($overrideReason) : null,
                'active_key'        => 1,
            ]);

            // M-14 — stamp every order whose material variance this
            // closing cleared, so it is never cleared a second time.
            foreach ($proposal['material_orders'] ?? [] as $m) {
                $model = $m['type'] === 'production' ? ProductionOrder::class : JobOrder::class;
                $model::withoutGlobalScopes()->whereKey($m['id'])->update([
                    'material_variance_closing_id' => $closing->id,
                    'material_variance_amount'     => $m['amount'],
                ]);
            }

            AuditLog::record($actor, 'manufacturing_variance_closing.posted', $closing, null, $closing->toArray());

            return $closing;
        });
    }

    /**
     * M-15 — an override must stay between 0 and the row's own total,
     * and any override that actually changes the calculated split needs
     * a written reason (kept on the closing and in the audit log).
     */
    private function assertOverridesAllowed(array $proposal, array $overrides, ?string $reason): void
    {
        $changed = false;
        $groups = [
            'products'   => ['product_splits', 'fg_amount', 'finished-goods'],
            'job_orders' => ['job_order_splits', 'remaining_segment_amount', 'remaining-segment'],
        ];

        foreach ($groups as $key => [$splitKey, $amountKey, $label]) {
            foreach (($overrides[$key] ?? []) as $id => $value) {
                $split = $proposal[$splitKey][$id] ?? null;
                if (! $split) {
                    continue;
                }
                $value = bcadd((string) $value, '0', 2);
                $share = $split['variance_share'];
                $lo = bccomp($share, '0', 2) < 0 ? $share : '0.00';
                $hi = bccomp($share, '0', 2) < 0 ? '0.00' : $share;
                if (bccomp($value, $lo, 2) < 0 || bccomp($value, $hi, 2) > 0) {
                    throw new \DomainException("The {$label} amount {$value} is outside the row's own total ({$lo} to {$hi}) — an override can only move the split, not change the total.");
                }
                if (bccomp($value, $split[$amountKey], 2) != 0) {
                    $changed = true;
                }
            }
        }

        if ($changed && trim((string) $reason) === '') {
            throw new \DomainException('A reason is required when the proposed split is overridden.');
        }
    }

    /**
     * M-15 — reverses a posted closing: the journal entry, the
     * finished-goods / job-segment value adjustments, and the
     * "already cleared" stamps on the orders. Only the most recent
     * closing can be reversed, and its period must still be open.
     */
    public function reverse(int $companyId, ManufacturingVarianceClosing $closing, User $actor, string $reason): ManufacturingVarianceClosing
    {
        if (trim($reason) === '') {
            throw new \DomainException('A reason is required to reverse a variance closing.');
        }
        if ($closing->company_id !== $companyId || $closing->isReversed()) {
            throw new \DomainException('This closing is not available to reverse.');
        }

        $later = ManufacturingVarianceClosing::where('company_id', $companyId)->whereNull('reversed_at')
            ->where(fn ($q) => $q->where('period_year', '>', $closing->period_year)
                ->orWhere(fn ($q2) => $q2->where('period_year', $closing->period_year)->where('period_month', '>', $closing->period_month)))
            ->exists();
        if ($later) {
            throw new \DomainException('A later period has been closed since — reverse the latest closing first.');
        }

        return DB::transaction(function () use ($companyId, $closing, $actor, $reason) {
            $before = $closing->toArray();
            $entryDate = \Carbon\Carbon::create($closing->period_year, $closing->period_month, 1)->endOfMonth()->toDateString();
            $snapshot = $closing->proposal_snapshot ?? [];

            $reversalEntry = null;
            if ($closing->journal_entry_id) {
                $reversalEntry = $this->journalEntryService->reverse(
                    $closing->journalEntry()->with('lines')->first(),
                    $actor,
                    "Reversal of Manufacturing Variance Closing {$closing->period_month}/{$closing->period_year} — {$reason}"
                );
            }

            foreach (($snapshot['product_splits'] ?? []) as $productId => $split) {
                $this->adjustProductBalance($companyId, (int) $productId, bcmul((string) ($split['fg_amount'] ?? '0'), '-1', 2), $entryDate, $closing->period_month, $closing->period_year, $actor, 'Manufacturing variance closing REVERSED');
            }
            foreach (($snapshot['job_order_splits'] ?? []) as $jobId => $split) {
                $job = JobOrder::with('stockBalance')->find($jobId);
                if ($job) {
                    $this->adjustJobOrderBalance($job, bcmul((string) ($split['remaining_segment_amount'] ?? '0'), '-1', 2), $entryDate, $closing->period_month, $closing->period_year, $actor, 'Manufacturing variance closing REVERSED');
                }
            }

            ProductionOrder::withoutGlobalScopes()->where('material_variance_closing_id', $closing->id)
                ->update(['material_variance_closing_id' => null, 'material_variance_amount' => null]);
            JobOrder::withoutGlobalScopes()->where('material_variance_closing_id', $closing->id)
                ->update(['material_variance_closing_id' => null, 'material_variance_amount' => null]);

            $closing->update([
                'reversed_at'               => now(),
                'reversed_by'               => $actor->id,
                'reversal_reason'           => trim($reason),
                'reversal_journal_entry_id' => $reversalEntry?->id,
                'active_key'                => null,
            ]);

            AuditLog::record($actor, 'manufacturing_variance_closing.reversed', $closing, $before, $closing->fresh()->toArray());

            return $closing->fresh();
        });
    }

    // ── Breakdown helpers ────────────────────────────────────────────

    private function sumBreakdown(array $breakdown): string
    {
        $total = bcadd($breakdown['labor'] ?? '0.00', $breakdown['material'] ?? '0.00', 2);
        foreach (($breakdown['overhead'] ?? []) as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    private function mergeBreakdown(array &$target, array $breakdown): void
    {
        $target['labor'] = bcadd($target['labor'] ?? '0.00', $breakdown['labor'] ?? '0.00', 2);
        $target['material'] = bcadd($target['material'] ?? '0.00', $breakdown['material'] ?? '0.00', 2);
        foreach (($breakdown['overhead'] ?? []) as $poolId => $amount) {
            $target['overhead'][$poolId] = bcadd($target['overhead'][$poolId] ?? '0.00', $amount, 2);
        }
    }

    private function soldVsEndingRatios(string $soldQty, string $endingQty): array
    {
        $availableQty = bcadd($soldQty, $endingQty, 4);
        if (bccomp($availableQty, '0', 4) <= 0) {
            return ['1', '0']; // nothing sold, nothing left — falls entirely to COGS by default
        }

        $cogsRatio = bcdiv($soldQty, $availableQty, 8);
        $endingRatio = bcsub('1', $cogsRatio, 8); // complement, so the two ratios always sum to exactly 1

        return [$cogsRatio, $endingRatio];
    }

    private function applyRatio(string $amount, string $ratio): string
    {
        return bcmul($amount, $ratio, 2);
    }

    private function scaleBreakdown(array $breakdown, string $ratio): array
    {
        $scaled = ['labor' => $this->applyRatio($breakdown['labor'] ?? '0.00', $ratio), 'material' => $this->applyRatio($breakdown['material'] ?? '0.00', $ratio), 'overhead' => []];
        foreach (($breakdown['overhead'] ?? []) as $poolId => $amount) {
            $scaled['overhead'][$poolId] = $this->applyRatio($amount, $ratio);
        }

        return $scaled;
    }

    /** @return array<string, string> keyed 'production:5' or 'job:12' => summed hours */
    private function hoursByOrder(\Illuminate\Support\Collection $entries, string $orderIdColumn, string $prefix): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $key = $prefix.':'.$entry->{$orderIdColumn};
            $result[$key] = bcadd($result[$key] ?? '0', (string) $entry->base_quantity, 4);
        }

        return $result;
    }

    // Adds Dr/Cr line pairs for one breakdown against one destination
    // account — labor portion clears from 514, each pool's portion
    // clears from 515 (515 is one account; the pool distinction lives
    // in the line's own description, same convention Payroll already
    // uses). A positive share = Dr destination / Cr variance account;
    // negative flips both sides automatically.
    private function addBreakdownLines(array &$lines, string $destinationAccountId, string $laborVarianceAccountId, string $overheadVarianceAccountId, array $breakdown, string $descriptionPrefix, ?string $wipAccountId = null): void
    {
        // Material variance sits in WIP itself (no 514/515 offset) —
        // it moves from WIP to the destination.
        $material = $breakdown['material'] ?? '0.00';
        if ($wipAccountId !== null && bccomp($material, '0', 2) != 0) {
            $this->addSignedPair($lines, $destinationAccountId, $wipAccountId, $material, "{$descriptionPrefix} (material)");
        }

        $labor = $breakdown['labor'] ?? '0.00';
        if (bccomp($labor, '0', 2) != 0) {
            $this->addSignedPair($lines, $destinationAccountId, $laborVarianceAccountId, $labor, "{$descriptionPrefix} (labor)");
        }

        foreach (($breakdown['overhead'] ?? []) as $poolId => $amount) {
            if (bccomp($amount, '0', 2) == 0) {
                continue;
            }
            $poolName = OverheadPool::find($poolId)?->name ?? "Pool #{$poolId}";
            $this->addSignedPair($lines, $destinationAccountId, $overheadVarianceAccountId, $amount, "{$descriptionPrefix} ({$poolName} overhead)");
        }
    }

    private function addSignedPair(array &$lines, string $destinationAccountId, string $sourceAccountId, string $amount, string $description): void
    {
        $isPositive = bccomp($amount, '0', 2) > 0;
        $abs = $isPositive ? $amount : bcmul($amount, '-1', 2);

        $lines[] = ['chart_of_account_id' => $destinationAccountId, 'debit' => $isPositive ? $abs : '0', 'credit' => $isPositive ? '0' : $abs, 'description' => $description];
        $lines[] = ['chart_of_account_id' => $sourceAccountId, 'debit' => $isPositive ? '0' : $abs, 'credit' => $isPositive ? $abs : '0', 'description' => $description];
    }

    private function adjustProductBalance(int $companyId, int $productId, string $fgAmount, string $entryDate, int $periodMonth, int $periodYear, User $actor, ?string $note = null): void
    {
        if (bccomp($fgAmount, '0', 2) == 0) {
            return;
        }

        // Audit M-16 — the general ledger has already been (or is about
        // to be) adjusted by exactly $fgAmount, so the stock value must
        // move by exactly the same amount. Silently skipping a missing
        // balance, or clamping at zero, used to leave the two apart.
        $balance = ProductStockBalance::where('company_id', $companyId)->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            throw new \DomainException("Product #{$productId} has no stock balance to carry a Finished Goods variance of {$fgAmount} — move that amount to COGS instead.");
        }

        $newValue = bcadd($balance->wac_total_value, $fgAmount, 2);
        if (bccomp($newValue, '0', 2) < 0) {
            throw new \DomainException("Product #{$productId}: the Finished Goods adjustment of {$fgAmount} would take its stock value below zero — adjust the split instead.");
        }
        $newWac = bccomp($balance->quantity_on_hand, '0', 4) > 0 ? bcdiv($newValue, $balance->quantity_on_hand, 4) : '0.0000';

        $balance->update(['wac_total_value' => $newValue, 'wac_unit_cost' => $newWac]);

        // RPT-003 fix (8 Sep 2026) — the balance update above used to
        // be the only trace of this event anywhere. StockLedgerService
        // ::categoryValueStatement() and its per-product ledger sum
        // stock_movements, so without a real row here they silently
        // drifted out of sync with product_stock_balances by exactly
        // this amount. Recorded as a real, dated, zero-quantity
        // movement — same category of event as SAP's MR21/MR22 "Price
        // Change" or Oracle's "Inventory value change" transactions
        // (see StockMovement::TYPE_VARIANCE_ABSORPTION_IN/_OUT header).
        $isIncrease = bccomp($fgAmount, '0', 2) > 0;
        StockMovement::create([
            'company_id'            => $companyId,
            'product_id'            => $productId,
            'inventory_location_id' => $this->resolveRepresentativeLocation($companyId, $productId),
            'movement_type'         => $isIncrease ? StockMovement::TYPE_VARIANCE_ABSORPTION_IN : StockMovement::TYPE_VARIANCE_ABSORPTION_OUT,
            'quantity'              => '0.0000',
            'wac_unit_cost'         => $newWac,
            'wac_total_value'       => $isIncrease ? $fgAmount : bcmul($fgAmount, '-1', 2),
            'reference_type'        => ManufacturingVarianceClosing::class,
            'reference_id'          => null,
            'movement_date'         => $entryDate,
            'created_by'            => $actor->id,
            'notes'                 => ($note ?? 'Manufacturing variance absorbed into Finished Goods')." — {$periodMonth}/{$periodYear}",
        ]);
    }

    /**
     * RPT-003 fix (8 Sep 2026) — a value-only revaluation has no
     * natural single location the way a real receipt or issue does
     * (product_stock_balances is explicitly company-wide, never
     * per-location — see that table's own migration header). Picks
     * the location currently holding the most of this product, as
     * the most representative place to attribute the revaluation to.
     * Falls back to the company's Master location (auto-created for
     * every company) if the product has no location stock recorded
     * yet — this should be rare, since a product with a Finished
     * Goods value to revalue normally already has stock somewhere.
     */
    private function resolveRepresentativeLocation(int $companyId, int $productId): int
    {
        $topLocationId = \App\Models\ProductLocationStock::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->orderByDesc('quantity_on_hand')
            ->value('inventory_location_id');

        if ($topLocationId) {
            return $topLocationId;
        }

        return \App\Models\InventoryLocation::where('company_id', $companyId)
            ->where('type', \App\Models\InventoryLocation::TYPE_MASTER)
            ->value('id');
    }

    private function adjustJobOrderBalance(JobOrder $job, string $remainingAmount, string $entryDate, int $periodMonth, int $periodYear, User $actor, ?string $note = null): void
    {
        if (bccomp($remainingAmount, '0', 2) == 0) {
            return;
        }

        $existing = $job->stockBalance;
        if (! $existing) {
            return;
        }

        $balance = JobOrderStockBalance::where('id', $existing->id)->lockForUpdate()->first();

        $newValue = bcadd($balance->total_value, $remainingAmount, 2);
        if (bccomp($newValue, '0', 2) < 0) {
            throw new \DomainException("Job Order {$job->job_code}: the segment adjustment of {$remainingAmount} would take its stock value below zero — adjust the split instead.");
        }
        $newUnit = bccomp($balance->quantity_on_hand, '0', 4) > 0 ? bcdiv($newValue, $balance->quantity_on_hand, 4) : '0.0000';

        $balance->update(['total_value' => $newValue, 'unit_cost' => $newUnit]);

        // Audit M-16 — leave the same dated, zero-quantity trace the
        // pooled path leaves, so the inventory value statements (which
        // sum stock movements) keep agreeing with the segment balance.
        $isIncrease = bccomp($remainingAmount, '0', 2) > 0;
        StockMovement::create([
            'company_id'            => $job->company_id,
            'product_id'            => $job->product_id,
            'inventory_location_id' => $job->output_location_id,
            'movement_type'         => $isIncrease ? StockMovement::TYPE_VARIANCE_ABSORPTION_IN : StockMovement::TYPE_VARIANCE_ABSORPTION_OUT,
            'quantity'              => '0.0000',
            'wac_unit_cost'         => $newUnit,
            'wac_total_value'       => $isIncrease ? $remainingAmount : bcmul($remainingAmount, '-1', 2),
            'job_order_id'          => $job->id,
            'reference_type'        => ManufacturingVarianceClosing::class,
            'reference_id'          => null,
            'movement_date'         => $entryDate,
            'created_by'            => $actor->id,
            'notes'                 => ($note ?? 'Manufacturing variance carried in the job segment')." — {$periodMonth}/{$periodYear}",
        ]);
    }

    // Re-splits one row using a person-supplied FG/remaining amount
    // instead of the calculated Sold-vs-Ending one — the TOTAL
    // (variance_share) never changes, only the ratio it's divided by,
    // which is exactly what scaleBreakdown() already does elsewhere,
    // just fed a person's ratio instead of a calculated one.
    private function applyOverrides(array $proposal, array $overrides): array
    {
        foreach (($overrides['products'] ?? []) as $productId => $overriddenFgAmount) {
            if (! isset($proposal['product_splits'][$productId])) {
                continue;
            }
            $split = &$proposal['product_splits'][$productId];
            $share = $split['variance_share'];

            $fgRatio = bccomp($share, '0', 8) != 0 ? bcdiv((string) $overriddenFgAmount, $share, 8) : '0';
            $cogsRatio = bcsub('1', $fgRatio, 8);

            // Reconstruct the underlying breakdown from cogs+fg (they
            // always summed to the full breakdown before override) so
            // the new ratio can be applied to labor/overhead exactly
            // as scaleBreakdown() expects.
            $fullBreakdown = $this->addBreakdowns($split['cogs_breakdown'], $split['fg_breakdown']);

            $split['fg_amount']      = $this->applyRatio($share, $fgRatio);
            $split['cogs_amount']    = $this->applyRatio($share, $cogsRatio);
            $split['fg_breakdown']   = $this->scaleBreakdown($fullBreakdown, $fgRatio);
            $split['cogs_breakdown'] = $this->scaleBreakdown($fullBreakdown, $cogsRatio);
            unset($split);
        }

        foreach (($overrides['job_orders'] ?? []) as $jobId => $overriddenRemainingAmount) {
            if (! isset($proposal['job_order_splits'][$jobId])) {
                continue;
            }
            $split = &$proposal['job_order_splits'][$jobId];
            $share = $split['variance_share'];

            $remainingRatio = bccomp($share, '0', 8) != 0 ? bcdiv((string) $overriddenRemainingAmount, $share, 8) : '0';
            $cogsRatio = bcsub('1', $remainingRatio, 8);

            $fullBreakdown = $this->addBreakdowns($split['cogs_breakdown'], $split['remaining_breakdown']);

            $split['remaining_segment_amount'] = $this->applyRatio($share, $remainingRatio);
            $split['cogs_amount']              = $this->applyRatio($share, $cogsRatio);
            $split['remaining_breakdown']      = $this->scaleBreakdown($fullBreakdown, $remainingRatio);
            $split['cogs_breakdown']           = $this->scaleBreakdown($fullBreakdown, $cogsRatio);
            unset($split);
        }

        return $proposal;
    }

    private function addBreakdowns(array $a, array $b): array
    {
        $result = ['labor' => bcadd($a['labor'] ?? '0.00', $b['labor'] ?? '0.00', 2), 'material' => bcadd($a['material'] ?? '0.00', $b['material'] ?? '0.00', 2), 'overhead' => []];
        $poolIds = array_unique(array_merge(array_keys($a['overhead'] ?? []), array_keys($b['overhead'] ?? [])));
        foreach ($poolIds as $poolId) {
            $result['overhead'][$poolId] = bcadd($a['overhead'][$poolId] ?? '0.00', $b['overhead'][$poolId] ?? '0.00', 2);
        }

        return $result;
    }

    private function resolveSystemAccount(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }

    // Trims models down to plain arrays so the whole proposal can be
    // stored as JSON on ManufacturingVarianceClosing for audit —
    // exactly what got calculated and posted, kept alongside the
    // journal entry it produced.
    private function serializeProposal(array $proposal): array
    {
        return json_decode(json_encode($proposal, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
    }
}
