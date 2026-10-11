<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  JobOrders
//
//  Make-to-order manufacturing: a job opened against a specific
//  customer order, consuming material, absorbing labour and overhead,
//  and completing into that job's OWN cost segment rather than the
//  company-wide pooled average.
//
//  This is the newest and least-exercised part of the system — the
//  live database has never contained a single job order — so the
//  cycle drives the whole lifecycle and checks both cost pools after
//  every step.
//
//  A KNOWN ASYMMETRY IS DELIBERATELY WALKED INTO. Production orders
//  take an entry date on every costing call:
//
//      ProductionOrderService::consumeMaterial($order, $product, $qty,
//                                              $actor, $entryDate, $notes)
//      JobOrderService::consumeMaterial($job, $product, $qty,
//                                              $actor, $entryDate, $notes)
//
//  Both take the work's own date now (MFG-006, fixed 2026-09), so a
//  consumption is costed into the period it happened in. The findings
//  below stay as regression guards: if the date is ever dropped from
//  either signature again, the consumption falls back to today, lands
//  outside every open period for a company whose books are in a past
//  fiscal year, and is refused — which is what these detect.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\JobOrderStockBalance;
use App\Models\OverheadPool;
use App\Services\Accounting\JobOrderService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;

class JobOrders
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(CycleContext $ctx, Carbon $monthStart): void
    {
        if ($ctx->finishedGoods === [] || $ctx->bomsByProduct === []) {
            return;
        }

        $verify       = new Verify($this->log, $ctx->companyId);
        $finishedGood = $ctx->finishedGoods[0];
        $recipe       = $ctx->bomsByProduct[$finishedGood->id] ?? null;

        if (! $recipe) {
            return;
        }

        $quantity = '5';
        $date     = $monthStart->copy()->addDays(11)->toDateString();

        // Enough raw material on hand for the job to be honest?
        foreach ($recipe['recipe'] as [$rawIndex, $perUnit]) {
            $raw    = $ctx->rawMaterials[$rawIndex];
            $needed = bcmul((string) $perUnit, $quantity, 4);

            if (! $ctx->book->canIssue($raw->id, $needed, $ctx->masterLocationId)) {
                return;
            }
        }

        // A job order is make-to-order, so it hangs off a real
        // customer order rather than existing on its own.
        $customer = $ctx->customers[0];
        $so = app(SalesOrderService::class)->create($ctx->companyId, [
            'partner_id'      => $customer->id,
            'subject'         => 'Bespoke build — '.$monthStart->format('M Y'),
            'currency_id'     => $ctx->currencyId,
            'exchange_rate'   => 1,
            'issuance_date'   => $date,
            'start_date'      => $date,
            'end_date'        => $monthStart->copy()->endOfMonth()->toDateString(),
            'payment_term_id' => $ctx->paymentTermId,
            'lines'           => [[
                'product_id'         => $finishedGood->id,
                'description'        => $finishedGood->name_en,
                'quantity'           => $quantity,
                'uom_id'             => $finishedGood->primary_uom_id,
                'unit_price'         => $ctx->price($finishedGood->id),
                'revenue_account_id' => $finishedGood->revenue_account_id,
            ]],
        ], $ctx->actor);
        app(SalesOrderService::class)->activate($so, $ctx->actor);

        $lines = [];
        foreach ($recipe['recipe'] as [$rawIndex, $perUnit]) {
            $raw = $ctx->rawMaterials[$rawIndex];
            $lines[] = [
                'component_product_id' => $raw->id,
                'quantity'             => bcmul((string) $perUnit, $quantity, 4),
                'uom_id'               => $raw->primary_uom_id,
            ];
        }

        $job = $this->gate->submit(
            \App\Http\Requests\Accounting\JobOrderRequest::class,
            [
                'product_id'               => $finishedGood->id,
                'quantity_to_produce'      => $quantity,
                'sales_order_id'           => $so->id,
                'raw_material_location_id' => $ctx->masterLocationId,
                'output_location_id'       => $ctx->masterLocationId,
                'planned_start_date'       => $date,
                'lines'                    => $lines,
            ],
            fn (array $data) => app(JobOrderService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open a job order — '.$monthStart->format('M Y'),
            'job order'
        );

        if (! $job) {
            return;
        }

        app(JobOrderService::class)->start($job, $ctx->actor);

        // ── Consume material ──────────────────────────────────────
        $consumedAll = true;

        foreach ($recipe['recipe'] as [$rawIndex, $perUnit]) {
            $raw    = $ctx->rawMaterials[$rawIndex];
            $needed = bcmul((string) $perUnit, $quantity, 4);

            try {
                // MFG-006 fix (2026-09) — the work's own date, so the
                // consumption is costed into the period it happened in
                // rather than into today.
                app(JobOrderService::class)->consumeMaterial($job, $raw->id, $needed, $ctx->actor, $date);
                $this->log->operation('job order consumption');
                $ctx->book->issue($raw->id, $needed, $ctx->masterLocationId);
            } catch (\Throwable $e) {
                $consumedAll = false;

                // THE IMPORTANT PART. The posting threw — but did the
                // STOCK move anyway? consumeMaterial() calls
                // StockLedgerService::issue(), which commits its own
                // transaction, and only then posts the journal entry.
                // Neither is wrapped in an outer transaction, so a
                // failure here leaves material gone from inventory with
                // no accounting entry behind it, permanently.
                $movedAnyway = \App\Models\StockMovement::withoutGlobalScopes()
                    ->where('company_id', $ctx->companyId)
                    ->where('product_id', $raw->id)
                    ->where('reference_type', \App\Models\JobOrder::class)
                    ->where('reference_id', $job->id)
                    ->exists();

                if ($movedAnyway) {
                    $this->log->finding(
                        prefix: 'MFG',
                        severity: Finding::SEVERITY_CRITICAL,
                        confidence: Finding::CONFIDENCE_CONFIRMED,
                        title: 'Material consumption is not atomic: stock left inventory but no journal entry was posted',
                        operation: 'Job order material consumption — '.$monthStart->format('M Y'),
                        expected: 'the stock movement and its journal entry to succeed or fail together',
                        actual: 'the stock movement committed; the journal entry threw and was not rolled back',
                        difference: $needed.' of '.$raw->name_en.' left inventory with no accounting entry',
                        source: 'JobOrderService::consumeMaterial() — and ProductionOrderService::consumeMaterial() '
                            .'has the same shape',
                        rootCause: 'StockLedgerService::issue() opens and COMMITS its own DB transaction, then the '
                            .'caller posts the journal entry as a separate statement. Neither consumeMaterial() '
                            .'wraps the pair in an outer transaction, so any failure in the posting step — a closed '
                            .'period, a missing account, an unbalanced entry — silently strands the inventory '
                            .'movement.',
                        evidence: 'A stock_movements row exists for this job order and component; no journal entry '
                            .'does. The inventory subsidiary ledger and the general ledger diverge from this moment '
                            .'on and never re-converge.',
                        context: [
                            'component'        => $raw->name_en,
                            'quantity gone'    => $needed,
                            'posting error'    => $e->getMessage(),
                        ],
                    );
                }

                // The stock is gone from the application and still
                // present in the cycle's book. Re-base the book onto
                // reality — the loss is recorded above as MFG; leaving
                // the book ahead would report the SAME loss again every
                // month afterwards as a fresh finding.
                if ($movedAnyway) {
                    $actual = $verify->inventoryState($raw->id);
                    $ctx->book->resyncTo($raw->id, $actual['quantity'], $actual['value'], $actual['wac']);
                    $ctx->book->resyncLocation($raw->id, $ctx->masterLocationId, $actual['quantity']);
                }

                $this->log->finding(
                    prefix: 'MFG',
                    severity: Finding::SEVERITY_HIGH,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'A job order cannot consume material against a past accounting period',
                    operation: 'Job order material consumption — '.$monthStart->format('M Y'),
                    expected: "the consumption to post on {$date}, the date the work was done",
                    actual: get_class($e).': '.$e->getMessage(),
                    source: 'JobOrderService::consumeMaterial()',
                    rootCause: 'consumeMaterial() takes no entry date, unlike '
                        .'ProductionOrderService::consumeMaterial($order, $product, $qty, $actor, $entryDate, $notes). '
                        .'The movement and its journal entry are therefore always dated today, so work done in any '
                        .'earlier period cannot be costed at all — and in a live company, last month\'s consumption '
                        .'lands in this month.',
                    context: [
                        'job order date'  => $date,
                        'posting date'    => now()->toDateString(),
                        'component'       => $raw->name_en,
                        'quantity'        => $needed,
                    ],
                );
                break;
            }
        }

        if (! $consumedAll) {
            return;
        }

        // ── Labour and overhead ───────────────────────────────────
        try {
            app(JobOrderService::class)->recordLaborCost(
                $job, bcmul($recipe['labor_hours'], $quantity, 2), $ctx->actor, $date
            );
            $this->log->operation('job order labour');

            $pool = OverheadPool::withoutGlobalScopes()->find($ctx->overheadPoolId);
            app(JobOrderService::class)->recordOverheadCost(
                $job, $pool, bcmul($recipe['machine_hours'], $quantity, 2), $ctx->actor, $date
            );
            $this->log->operation('job order overhead');
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'MFG',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Recording labour or overhead on a job order failed',
                operation: 'Job order costing — '.$monthStart->format('M Y'),
                expected: 'labour and overhead to post to work in progress',
                actual: get_class($e).': '.$e->getMessage(),
            );

            return;
        }

        // ── Complete ──────────────────────────────────────────────
        $completed = $this->gate->submit(
            null,
            [],
            fn () => app(JobOrderService::class)->complete($job->fresh(), $ctx->actor, $date),
            'Complete a job order — '.$monthStart->format('M Y'),
            'job order completion'
        );

        if (! $completed) {
            return;
        }

        // The job's own segment must now hold the output. This is the
        // second cost pool, and nothing else in the system tracks it.
        $segment = JobOrderStockBalance::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('job_order_id', $job->id)
            ->where('product_id', $finishedGood->id)
            ->first();

        $verify->truth(
            $segment !== null,
            'A completed job order created no entry in its own cost segment',
            'Job order completion — '.$monthStart->format('M Y'),
            prefix: 'MFG'
        );

        if ($segment) {
            $verify->quantity(
                $quantity,
                (string) $segment->quantity_on_hand,
                'The job order segment holds the wrong quantity',
                'Job order completion — '.$monthStart->format('M Y'),
                prefix: 'MFG'
            );
        }

        // REGRESSION GUARD — audit finding MFG-001. The pooled
        // company-wide quantity is deliberately not raised by a job
        // order receipt, contradicting the documented rule that only
        // the pooled COST is skipped. Recorded, not worked around.
        $pooledQty = $verify->inventoryState($finishedGood->id)['quantity'];
        $bookQty   = $ctx->book->quantity($finishedGood->id);

        if (bccomp($pooledQty, $bookQty, 4) !== 0) {
            $this->log->note(
                '  (job order output is held outside the pooled balance — audit finding MFG-001; '
                ."pooled {$pooledQty}, job segment {$quantity})"
            );
        }

        $verify->ledgerBalanced('Job order completion — '.$monthStart->format('M Y'));
    }
}
