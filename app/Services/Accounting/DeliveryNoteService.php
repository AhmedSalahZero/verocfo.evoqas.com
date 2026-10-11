<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeliveryNoteService
//
//  Delivery & Revenue Recognition v1.0 — see
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md, the
//  authoritative reference for every decision below.
//
//  The outbound mirror of GoodsReceiptNoteService. post() does three
//  things per line, in order:
//
//    1. Physically decreases stock via the real
//       StockLedgerService::issue() call — WAC cost is READ from its
//       result, never recomputed here.
//    2. Reads the SO line's net delivery position (migration 000128:
//       delivered_quantity - invoiced_quantity, BEFORE this delivery
//       is applied) to decide how this line's quantity splits between
//       clearing existing Deferred Revenue (this SO line was invoiced
//       ahead of delivery) and creating fresh Accrued Revenue (the
//       normal case) — design doc Section 8.3.
//    3. Posts TWO separate journal entries — Dr COGS/Cr Inventory,
//       and Dr 143-and/or-236/Cr Revenue — both linked on the
//       DeliveryNote header, since together they represent the two
//       genuinely different things this single event does (physical
//       stock movement vs. revenue recognition), even though they
//       happen at the same moment for the same reason.
//
//  setToDraft() reopens a posted Delivery Note, automatically
//  reversing all of the above — both journal entries, the SO line's
//  delivered_quantity, and the stock itself. Putting stock BACK is
//  mechanically an inbound movement, so this reuses
//  StockLedgerService::receive() directly (same trick already used
//  by StockLedgerService::adjust()'s 'in' direction) rather than a
//  separate method — receive() already recalculates WAC correctly
//  for stock coming back into the pool. Blocked once a Customer
//  Invoice has been created against this delivery (3-way link:
//  SO → Delivery → Invoice), or once its accounting period is
//  closed. Mirrors GoodsReceiptNoteService::setToDraft() closely —
//  see that method's doc comment for the same reasoning applied to
//  the inbound side.
//
//  VAT never appears in either entry (design doc Section 3 — VAT
//  recognizes at invoice only).
//
//  JOB ORDER SEGMENT AWARENESS (2026-09, Manufacturing Variance
//  Closing Design v1.0, Section 6, piece 3): before issuing stock,
//  post() checks whether a completed Job Order — tied to this SAME
//  Sales Order, for this exact Product — still holds stock in its own
//  valuation segment (JobOrderStockBalance). If so, this line draws
//  from that job's own cost automatically, never the pooled company
//  WAC. If the segment can't cover the full line quantity,
//  JobOrderStockBalanceService::issue() throws BEFORE any write
//  happens — the person must split the line in two rather than one
//  line silently blending two different costs. setToDraft() mirrors
//  this on the way back in, restoring stock to the ORIGINATING job's
//  segment (read from the reversed movement's own job_order_id), not
//  the pooled balance it never came from.
//
//  AUDIT C8 FIX (Sept 2026) — foreign-currency Sales Orders. Revenue
//  used to be booked at quantity × SO price with NO exchange rate, so
//  a USD order's revenue and 143 went into the EGP ledger 1:1. Now:
//    • Fresh Accrued Revenue (143) = quantity × price × the SO
//      currency's rate on the DELIVERY date (FxService). Functional
//      (EGP) orders: rate 1, figures unchanged.
//    • Clearing Deferred Revenue (236) takes the SO line's booked EGP
//      balance, pro rata by quantity — exactly what the invoice put
//      there — and recognises that same amount as revenue (IFRIC 22:
//      revenue for an advance is fixed at the advance's own rate).
//    • Both EGP figures are stored on the line and on the SO line's
//      running balances (migration 000294), so Set to Draft and the
//      later invoice clear exactly what was booked.
//
//  @layer    Service
//  @depends  FxService,
//            DeliveryNote, DeliveryNoteLine, SalesOrder, SalesOrderLine,
//            CustomerInvoiceLine, Product, ChartOfAccount, JobOrder,
//            StockLedgerService, JournalEntryService, FiscalYearService,
//            JobOrderStockBalanceService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CustomerInvoiceLine;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\JobOrder;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ProductStockBalance;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeliveryNoteService
{
    public function __construct(
        private StockLedgerService $stockLedgerService,
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private JobOrderStockBalanceService $jobOrderStockBalances,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return DeliveryNote::with(['partner:id,name_en,name_ar', 'salesOrder:id,so_code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('delivery_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($filters['sales_order_id'] ?? null, fn ($q, $so) => $q->where('sales_order_id', $so))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) ────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): DeliveryNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['company_id']     = $companyId;
            $data['created_by']     = $actor->id;
            $data['status']         = DeliveryNote::STATUS_DRAFT;

            $delivery = $this->createWithUniqueCode($companyId, $data);

            $this->syncLines($delivery, $companyId, $lines);

            AuditLog::record($actor, 'delivery_note.created', $delivery, null, $delivery->fresh('lines')->toArray());

            return $delivery->fresh(['partner', 'salesOrder', 'lines.location']);
        });
    }

    public function update(int $companyId, DeliveryNote $delivery, array $data, User $actor): DeliveryNote
    {
        if (! $delivery->isDraft()) {
            throw new \DomainException('Only a draft Delivery Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $delivery, $data, $actor) {
            $before = $delivery->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines']);

            $data['updated_by'] = $actor->id;

            // A Delivery Note can only ever be edited while draft
            // (guarded above), so it's always safe to regenerate
            // delivery_code here if delivery_date genuinely changed
            // — same reasoning as GoodsReceiptNoteService's
            // saveWithUniqueCode().
            $this->saveWithUniqueCode($delivery, $data);

            $this->syncLines($delivery, $companyId, $lines);

            AuditLog::record($actor, 'delivery_note.updated', $delivery, $before, $delivery->fresh('lines')->toArray());

            return $delivery->fresh(['partner', 'salesOrder', 'lines.location']);
        });
    }

    public function delete(DeliveryNote $delivery, User $actor): void
    {
        if (! $delivery->isDraft()) {
            throw new \DomainException('Only a draft Delivery Note can be deleted.');
        }

        $before = $delivery->toArray();
        $delivery->lines()->delete();
        $delivery->delete();
        AuditLog::record($actor, 'delivery_note.deleted', $delivery, $before, null);
    }

    // ── Post — the real engine ───────────────────────────────────

    public function post(DeliveryNote $delivery, User $actor): DeliveryNote
    {
        if (! $delivery->isDraft()) {
            throw new \DomainException('Only a draft Delivery Note can be posted.');
        }

        $delivery->loadMissing('lines.product', 'lines.salesOrderLine');

        if ($delivery->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Delivery Note with no lines.');
        }

        // Rule 26.5 extension (confirmed with Mahmoud, September 2026):
        // a Delivery Note issues stock at its current average cost —
        // back-dating it past a newer fiscal year risks the same kind
        // of inconsistency the Goods Receipt Note hard block exists to
        // prevent, just on the issue side instead of the receipt side.
        if ($this->fiscalYearService->newerFiscalYearExists($delivery->company_id, $delivery->delivery_date->toDateString())) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Delivery Note [{$delivery->delivery_code}] — its date ({$delivery->delivery_date->toDateString()}) falls in a fiscal year that has since been superseded by a newer one. Delivery Notes cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
            );
        }

        foreach ($delivery->lines as $line) {
            if (! $line->location_id) {
                throw new \DomainException("Line for {$line->description} is missing its Shipping From location.");
            }
        }

        // C8: one rate for the whole delivery — the SO currency's rate on
        // the delivery date. Refuses to post if a foreign rate is missing.
        $deliveryRate = app(FxService::class)->rateOn(
            $delivery->company_id,
            $delivery->salesOrder?->currency_id ? (int) $delivery->salesOrder->currency_id : null,
            $delivery->delivery_date->toDateString()
        );

        return DB::transaction(function () use ($delivery, $actor, $deliveryRate) {
            $before = $delivery->toArray();

            $cogsBuckets      = []; // chart_of_account_id => functional amount (debit)
            $inventoryBuckets = []; // chart_of_account_id => functional amount (credit)
            $accruedTotal     = '0'; // Dr 143, summed across all lines
            $deferredTotal    = '0'; // Dr 236, summed across all lines
            $revenueBuckets   = []; // chart_of_account_id => functional amount (credit)

            foreach ($delivery->lines as $line) {
                $soLine = SalesOrderLine::lockForUpdate()->findOrFail($line->sales_order_line_id);
                $product = $line->product;

                // Final guard — re-checked under lock, since time can pass
                // between this draft being saved (syncLines() already
                // checked once) and it actually being posted; another
                // delivery could have posted against the same SO line
                // in between.
                $remaining = bcsub((string) $soLine->quantity, (string) $soLine->delivered_quantity, 4);
                if (bccomp((string) $line->quantity, $remaining, 4) > 0) {
                    throw new \DomainException("Delivering {$line->quantity} of {$soLine->description} exceeds what's left on the Sales Order ({$remaining} remaining) — another delivery may have posted since this draft was saved.");
                }

                // Manufacturing Variance Closing Design v1.0, Section
                // 6, piece 3 — if a Job Order tied to this SAME Sales
                // Order produced this exact product and still holds
                // stock in its own segment, this delivery draws from
                // THAT job's own cost automatically (Section 6.5 — no
                // manual picking) instead of the pooled company WAC.
                // Confirmed with Mahmoud: never blend the two on one
                // line — if the segment can't cover the full line
                // quantity, jobOrderStockBalances->issue() below
                // throws, telling the user to split it into two lines
                // instead of silently mixing costs.
                $eligibleJobOrder = JobOrder::where('company_id', $delivery->company_id)
                    ->where('sales_order_id', $delivery->sales_order_id)
                    ->where('product_id', $product->id)
                    ->where('status', JobOrder::STATUS_COMPLETED)
                    ->whereHas('stockBalance', fn ($q) => $q->where('quantity_on_hand', '>', 0))
                    ->with('stockBalance')
                    ->first();

                // 1. Real stock movement — WAC read from its result, never recomputed here.
                //
                // Fix (2026-09) — a project-linked Sales Order's
                // delivery was unconditionally hitting the
                // dual-valuation rule: StockLedgerService::issue()
                // requires actual_unit_cost whenever a project_id is
                // present on the movement, and this call already sent
                // project_id (correctly, for job-costing) but never
                // supplied actual_unit_cost, so EVERY delivery against
                // a project-linked SO failed outright. VeroCFO costs
                // inventory on a company-wide WAC basis (not FIFO /
                // specific-identification), so "the actual cost" for
                // this purpose is simply that WAC at the moment of
                // issue — the same figure issue() itself is about to
                // use internally for the COGS/inventory posting a few
                // lines down; this is a plain read of the same
                // balance row, not a second, different valuation.
                // (Not used at all on the Job-Order-segment path below
                // — that path has its own, more specific cost.)
                $actualUnitCost = ProductStockBalance::where('company_id', $delivery->company_id)
                    ->where('product_id', $product->id)
                    ->value('wac_unit_cost');

                if ($eligibleJobOrder) {
                    // Throws here (before any write) if the segment
                    // can't cover the full line quantity — exactly the
                    // forced-split behavior Section 6.5 confirmed.
                    // Called BEFORE stockLedgerService->issue() so a
                    // shortfall never leaves a half-posted movement.
                    $this->jobOrderStockBalances->issue($eligibleJobOrder, $product, (string) $line->quantity);

                    $movement = $this->stockLedgerService->issue(
                        companyId: $delivery->company_id,
                        product: $product,
                        inventoryLocationId: $line->location_id,
                        quantity: (string) $line->quantity,
                        actor: $actor,
                        options: [
                            'reference_type' => 'delivery_note_line',
                            'reference_id'   => $line->id,
                            'job_order_id'   => $eligibleJobOrder->id,
                            'unit_cost'      => (string) $eligibleJobOrder->stockBalance->unit_cost,
                            'movement_date'  => $delivery->delivery_date,
                        ],
                    );
                } else {
                    $movement = $this->stockLedgerService->issue(
                        companyId: $delivery->company_id,
                        product: $product,
                        inventoryLocationId: $line->location_id,
                        quantity: (string) $line->quantity,
                        actor: $actor,
                        options: [
                            'reference_type'   => 'delivery_note_line',
                            'reference_id'     => $line->id,
                            'project_id'       => $delivery->salesOrder->project_id,
                            'actual_unit_cost' => $actualUnitCost,
                            'movement_date'    => $delivery->delivery_date,
                        ],
                    );
                }

                $this->addToBucket($cogsBuckets, $product->cogs_account_id, (string) $movement->wac_total_value);
                $this->addToBucket($inventoryBuckets, $product->inventory_account_id, (string) $movement->wac_total_value);

                // 2. Net position BEFORE this delivery — design doc Section 8.3 / migration 000128 header.
                $netBefore = bcsub((string) $soLine->delivered_quantity, (string) $soLine->invoiced_quantity, 4);
                $lineQty   = (string) $line->quantity;

                $outstandingDeferredQty = '0';
                if (bccomp($netBefore, '0', 4) >= 0) {
                    $deferredClearedQty = '0';
                    $accruedCreatedQty  = $lineQty;
                } else {
                    $outstandingDeferredQty = bcmul($netBefore, '-1', 4); // netBefore is negative — flip sign
                    $deferredClearedQty = bccomp($lineQty, $outstandingDeferredQty, 4) <= 0 ? $lineQty : $outstandingDeferredQty;
                    $accruedCreatedQty  = bcsub($lineQty, $deferredClearedQty, 4);
                }

                // C8: 236 cleared at its booked EGP value (pro rata);
                // fresh 143 at today's rate.
                $deferredClearedRevenue = bccomp($deferredClearedQty, '0', 4) > 0
                    ? $this->proRata((string) $soLine->deferred_revenue_functional, $deferredClearedQty, $outstandingDeferredQty)
                    : '0.00';
                $accruedCreatedRevenue  = app(FxService::class)->toFunctional(
                    bcmul($accruedCreatedQty, (string) $line->unit_price, 8),
                    $deliveryRate
                );

                $deferredTotal = bcadd($deferredTotal, $deferredClearedRevenue, 2);
                $accruedTotal  = bcadd($accruedTotal, $accruedCreatedRevenue, 2);
                $this->addToBucket($revenueBuckets, $soLine->revenue_account_id, bcadd($deferredClearedRevenue, $accruedCreatedRevenue, 2));

                $line->update([
                    'wac_unit_cost'             => $movement->wac_unit_cost,
                    'wac_total_value'           => $movement->wac_total_value,
                    'stock_movement_id'         => $movement->id,
                    'deferred_cleared_quantity' => $deferredClearedQty,
                    'accrued_created_quantity'  => $accruedCreatedQty,
                    'deferred_cleared_functional' => $deferredClearedRevenue,
                    'accrued_created_functional'  => $accruedCreatedRevenue,
                ]);

                $soLine->update([
                    'delivered_quantity'          => bcadd((string) $soLine->delivered_quantity, $lineQty, 4),
                    'accrued_revenue_functional'  => bcadd((string) $soLine->accrued_revenue_functional, $accruedCreatedRevenue, 2),
                    'deferred_revenue_functional' => bcsub((string) $soLine->deferred_revenue_functional, $deferredClearedRevenue, 2),
                ]);
            }

            // ── Entry 1 — Dr COGS / Cr Inventory ──
            $inventoryLines = [];
            foreach ($cogsBuckets as $accountId => $amount) {
                $inventoryLines[] = ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'COGS — Delivery '.$delivery->delivery_code];
            }
            foreach ($inventoryBuckets as $accountId => $amount) {
                $inventoryLines[] = ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Inventory — Delivery '.$delivery->delivery_code];
            }
            $inventoryEntry = $this->journalEntryService->post(
                companyId: $delivery->company_id,
                entryDate: $delivery->delivery_date,
                lines: $inventoryLines,
                sourceType: 'delivery_note',
                sourceId: $delivery->id,
                narration: "Delivery {$delivery->delivery_code} — COGS/Inventory — {$delivery->partner->name_en}",
                actor: $actor,
            );

            // ── Entry 2 — Dr 143 and/or 236 / Cr Revenue ──
            $revenueLines = [];
            if (bccomp($accruedTotal, '0', 2) > 0) {
                $accruedAccount = $this->resolveAccruedRevenueAccount($delivery->company_id);
                $revenueLines[] = ['chart_of_account_id' => $accruedAccount->id, 'debit' => $accruedTotal, 'credit' => '0', 'description' => 'Accrued Revenue — Delivery '.$delivery->delivery_code];
            }
            if (bccomp($deferredTotal, '0', 2) > 0) {
                $deferredAccount = $this->resolveDeferredRevenueAccount($delivery->company_id);
                $revenueLines[] = ['chart_of_account_id' => $deferredAccount->id, 'debit' => $deferredTotal, 'credit' => '0', 'description' => 'Deferred Revenue cleared — Delivery '.$delivery->delivery_code];
            }
            foreach ($revenueBuckets as $accountId => $amount) {
                $revenueLines[] = ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Revenue recognized — Delivery '.$delivery->delivery_code];
            }
            $revenueEntry = $this->journalEntryService->post(
                companyId: $delivery->company_id,
                entryDate: $delivery->delivery_date,
                lines: $revenueLines,
                sourceType: 'delivery_note',
                sourceId: $delivery->id,
                narration: "Delivery {$delivery->delivery_code} — Revenue recognized — {$delivery->partner->name_en}",
                actor: $actor,
            );

            $delivery->update([
                'status'                     => DeliveryNote::STATUS_POSTED,
                'inventory_journal_entry_id' => $inventoryEntry->id,
                'revenue_journal_entry_id'   => $revenueEntry->id,
                'posted_by'                  => $actor->id,
                'posted_at'                  => now(),
            ]);

            AuditLog::record($actor, 'delivery_note.posted', $delivery, $before, $delivery->fresh()->toArray());

            return $delivery->fresh(['partner', 'salesOrder', 'lines.location', 'inventoryJournalEntry.lines', 'revenueJournalEntry.lines']);
        });
    }

    // ── Set to Draft (reopen a posted Delivery Note) ────────────────

    /**
     * Reopens a posted Delivery Note back to draft, so it can be
     * edited or deleted. Mirrors GoodsReceiptNoteService::setToDraft()
     * — automatically reverses EVERYTHING the posting did: both
     * journal entries, the stock, and the parent SO line's
     * delivered_quantity.
     *
     * Blocked if:
     *   - it's not currently posted (nothing to reopen)
     *   - a Customer Invoice already exists against any line of this
     *     delivery — the 3-way link (SO → Delivery → Invoice) already
     *     relies on this delivery being real; reopening it would
     *     leave that invoice pointing at a delivery that no longer
     *     happened
     *   - its accounting period is closed (Rule: closed periods
     *     allow no postings, no edits, no reversals)
     *
     * Unlike reopening a Goods Receipt Note, putting stock BACK can
     * never push a quantity negative — it can only ever restore
     * stock, never remove more than exists — so there is no
     * "stock has already moved on" block here the way there is for
     * GRN's setToDraft().
     */
    public function setToDraft(DeliveryNote $delivery, User $actor): DeliveryNote
    {
        if (! $delivery->isPosted()) {
            throw new \DomainException('Only a posted Delivery Note can be set back to draft.');
        }

        $this->assertNoCustomerInvoiceAgainst($delivery);

        // Audit H16 — goods from this delivery have come back on a Sales
        // Return, which relies on this delivery's cost and revenue.
        // Also any delivery on the same SO lines: a return reverses accrued
        // revenue from the SO line's pooled balance, which reopening another
        // delivery would then subtract a second time.
        $soLineIds = $delivery->lines()->pluck('sales_order_line_id');
        if ($delivery->lines()->where('returned_quantity', '>', 0)->exists()
            || \App\Models\SalesReturnLine::whereIn('sales_order_line_id', $soLineIds)
                ->whereHas('salesReturn', fn ($q) => $q->where('status', \App\Models\SalesReturn::STATUS_POSTED))->exists()) {
            throw new \DomainException('Goods on this order have been returned — cancel the Sales Return first, then reopen the delivery.');
        }

        if ($this->fiscalYearService->isPeriodClosed($delivery->company_id, $delivery->delivery_date->toDateString())) {
            throw new \DomainException('This Delivery Note\'s accounting period is closed — it cannot be reopened.');
        }

        $delivery->loadMissing('lines.product', 'lines.salesOrderLine', 'salesOrder');

        return DB::transaction(function () use ($delivery, $actor) {
            $before = $delivery->toArray();

            foreach ($delivery->lines as $line) {
                // Manufacturing Variance Closing Design v1.0, Section
                // 6, piece 3 — if this line was originally issued from
                // a Job Order's own segment (job_order_id set on its
                // stock movement), reversing it must put the stock
                // BACK into that job's segment, never the pooled
                // balance it never came from.
                $originalMovement = $line->stockMovement;
                $originatingJobOrder = $originalMovement?->job_order_id
                    ? JobOrder::with('stockBalance')->find($originalMovement->job_order_id)
                    : null;

                // Put the stock back. Mechanically this is stock
                // coming BACK into the pool, so it's an inbound
                // movement — reusing receive() directly gets the WAC
                // recalculation right for free, exactly the same
                // trick StockLedgerService::adjust()'s 'in' direction
                // already relies on.
                $this->stockLedgerService->receive(
                    $delivery->company_id,
                    $line->product,
                    $line->location_id,
                    (string) $line->quantity,
                    (string) $line->wac_unit_cost,
                    $actor,
                    [
                        'movement_type'  => StockMovement::TYPE_ISSUE_REVERSAL,
                        'project_id'     => $originatingJobOrder ? null : ($delivery->salesOrder->project_id ?? null),
                        // Audit H16 (Sept 2026) — the dual-valuation rule needs
                        // actual_unit_cost whenever a project is tagged; without
                        // it every project delivery failed to reopen.
                        'actual_unit_cost' => (! $originatingJobOrder && $delivery->salesOrder->project_id) ? (string) $line->wac_unit_cost : null,
                        'job_order_id'   => $originatingJobOrder?->id,
                        'reference_type' => 'delivery_note_line',
                        'reference_id'   => $line->id,
                        'movement_date'  => $delivery->delivery_date->toDateString(),
                        'notes'          => 'Reversal of an earlier delivery — Delivery Note reopened to draft.',
                    ]
                );

                if ($originatingJobOrder) {
                    $this->jobOrderStockBalances->receive(
                        $originatingJobOrder,
                        $line->product,
                        (string) $line->quantity,
                        (string) $line->wac_unit_cost
                    );
                }

                $soLine = $line->salesOrderLine ?? SalesOrderLine::lockForUpdate()->find($line->sales_order_line_id);
                $soLine->update([
                    'delivered_quantity'          => bcsub((string) $soLine->delivered_quantity, (string) $line->quantity, 4),
                    // C8: undo exactly the EGP amounts this line moved.
                    'accrued_revenue_functional'  => bcsub((string) $soLine->accrued_revenue_functional, (string) $line->accrued_created_functional, 2),
                    'deferred_revenue_functional' => bcadd((string) $soLine->deferred_revenue_functional, (string) $line->deferred_cleared_functional, 2),
                ]);

                // Clears the posting-time figures this line was given
                // — post() will recompute them fresh if this delivery
                // is edited and posted again.
                $line->update([
                    'wac_unit_cost'             => 0,
                    'wac_total_value'           => 0,
                    'stock_movement_id'         => null,
                    'deferred_cleared_quantity' => 0,
                    'accrued_created_quantity'  => 0,
                    'deferred_cleared_functional' => 0,
                    'accrued_created_functional'  => 0,
                ]);
            }

            if ($delivery->inventory_journal_entry_id) {
                $entry = JournalEntry::findOrFail($delivery->inventory_journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$delivery->delivery_code);
            }
            if ($delivery->revenue_journal_entry_id) {
                $entry = JournalEntry::findOrFail($delivery->revenue_journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$delivery->delivery_code);
            }

            $delivery->update([
                'status'                     => DeliveryNote::STATUS_DRAFT,
                'inventory_journal_entry_id' => null,
                'revenue_journal_entry_id'   => null,
                'posted_by'                  => null,
                'posted_at'                  => null,
                'updated_by'                 => $actor->id,
            ]);

            AuditLog::record($actor, 'delivery_note.set_to_draft', $delivery, $before, $delivery->fresh()->toArray());

            return $delivery->fresh(['partner', 'salesOrder', 'lines.location', 'lines.product']);
        });
    }

    /**
     * Blocks reopening a Delivery Note once a Customer Invoice has
     * been created against any of its lines — see setToDraft()'s
     * doc comment for why.
     */
    private function assertNoCustomerInvoiceAgainst(DeliveryNote $delivery): void
    {
        $lineIds = $delivery->lines()->pluck('id');

        $invoiced = CustomerInvoiceLine::whereIn('delivery_note_line_id', $lineIds)->exists();

        if ($invoiced) {
            throw new \DomainException(
                'A Customer Invoice already exists against this Delivery Note — it cannot be reopened. ' .
                'The invoice must be reversed first if this delivery genuinely needs correcting.'
            );
        }
    }

    public function cancel(DeliveryNote $delivery, User $actor): DeliveryNote
    {
        if (! $delivery->isDraft()) {
            throw new \DomainException('Only a draft Delivery Note can be cancelled — a posted delivery requires a Sales Return (not yet built — see design doc Section 8 header).');
        }

        return DB::transaction(function () use ($delivery, $actor) {
            $before = $delivery->toArray();
            $delivery->update(['status' => DeliveryNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'delivery_note.cancelled', $delivery, $before, $delivery->fresh()->toArray());
            return $delivery->fresh();
        });
    }

    // ── Private ───────────────────────────────────────────────────

    /**
     * C8: the share of an EGP balance that belongs to $part of $whole
     * units. Clearing the last unit takes whatever is left, so the
     * balance always ends at exactly zero.
     */
    private function proRata(string $balance, string $part, string $whole): string
    {
        if (bccomp($whole, '0', 4) <= 0 || bccomp($part, $whole, 4) >= 0) {
            return bcadd($balance, '0', 2);
        }

        $share = bcdiv(bcmul($balance, $part, 10), $whole, 10);
        $offset = str_starts_with($share, '-') ? '-0.005' : '0.005';

        return bcadd($share, $offset, 2);
    }

    private function resolveAccruedRevenueAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '143')
            ?? throw new \DomainException('Chart of Accounts is missing system account 143 (Accrued Revenue) — check the seeder.');
    }

    private function resolveDeferredRevenueAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '236')
            ?? throw new \DomainException('Chart of Accounts is missing system account 236 (Deferred Revenue) — check the seeder.');
    }

    private function addToBucket(array &$buckets, ?int $accountId, string $amount): void
    {
        if (! $accountId) {
            throw new \DomainException('A product used on this Delivery Note is missing a required Chart of Accounts assignment.');
        }
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    /**
     * Fix (July 2026, per Mahmoud):
     *   - location_id is now required per line (moved off the header,
     *     migration 000133) — a delivery can ship different lines of
     *     the same product from different locations.
     *   - Same (product, location) pair cannot appear twice within one
     *     Delivery Note — if more of the same product is needed from
     *     the same place, that's the SAME line with a bigger quantity,
     *     not two lines duplicating each other. A genuinely different
     *     location for the rest of the quantity is a second line.
     *   - Quantity can never exceed what's actually left to deliver on
     *     the SO line — checked here (at draft save, for immediate
     *     feedback) AND again in post() under a row lock (for the
     *     final, race-condition-safe guard, since time can pass
     *     between saving a draft and posting it).
     */
    private function syncLines(DeliveryNote $delivery, int $companyId, array $lines): void
    {
        $delivery->lines()->delete();

        $seenPairs = [];       // "product_id:location_id" => true
        $qtyBySoLine = [];     // sales_order_line_id => cumulative quantity across THIS delivery's lines

        foreach ($lines as $i => $line) {
            $soLine = SalesOrderLine::findOrFail($line['sales_order_line_id']);
            $quantity = (string) ($line['quantity'] ?? '0');
            $locationId = $line['location_id'] ?? null;

            if (! $locationId) {
                throw new \DomainException("Line for {$soLine->description} needs a Shipping From location.");
            }

            $pairKey = $soLine->product_id . ':' . $locationId;
            if (isset($seenPairs[$pairKey])) {
                throw new \DomainException("{$soLine->description} is already on this Delivery Note shipping from that same location — increase that line's quantity instead of adding a duplicate.");
            }
            $seenPairs[$pairKey] = true;

            $qtyBySoLine[$soLine->id] = bcadd($qtyBySoLine[$soLine->id] ?? '0', $quantity, 4);
            $remaining = bcsub((string) $soLine->quantity, (string) $soLine->delivered_quantity, 4);
            if (bccomp($qtyBySoLine[$soLine->id], $remaining, 4) > 0) {
                throw new \DomainException("Delivering {$qtyBySoLine[$soLine->id]} of {$soLine->description} exceeds what's left on the Sales Order ({$remaining} remaining).");
            }

            DeliveryNoteLine::create([
                'company_id'           => $companyId,
                'delivery_note_id'     => $delivery->id,
                'sales_order_line_id'  => $soLine->id,
                'location_id'          => $locationId,
                'product_id'           => $soLine->product_id,
                'description'          => $line['description'] ?? $soLine->description,
                'quantity'             => $quantity,
                'uom_id'               => $line['uom_id'] ?? $soLine->uom_id,
                'unit_price'           => $soLine->unit_price, // always the SO's own price — confirmed by Mahmoud
                'line_revenue_amount'  => bcmul($quantity, (string) $soLine->unit_price, 2),
                'wac_unit_cost'        => 0, // populated at post() from the real StockLedgerService call
                'wac_total_value'      => 0,
                'sort_order'           => $i + 1,
            ]);
        }
    }

    /**
     * delivery_code's YYYY/MM comes from deliveryDate — the
     * delivery's real business date, already the same field the
     * journal entries post against — not from "now." $excludeDnId
     * matters only when regenerating an existing draft's code
     * because delivery_date just changed (see saveWithUniqueCode()).
     */
    private function generateDeliveryCode(int $companyId, \Carbon\Carbon $deliveryDate, ?int $excludeDnId = null): string
    {
        $year  = $deliveryDate->format('Y');
        $month = $deliveryDate->format('m');

        $count = DeliveryNote::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('company_id', $companyId)
            ->whereYear('delivery_date', $year)
            ->whereMonth('delivery_date', $month)
            ->when($excludeDnId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->count() + 1;

        return 'DN/' . $year . '/' . $month . '/' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Saves a brand new Delivery Note with a guaranteed-unique
     * delivery_code. Same self-healing retry as
     * GoodsReceiptNoteService::createWithUniqueCode() — see that
     * method's comment for the full explanation.
     */
    private function createWithUniqueCode(int $companyId, array $data, int $maxAttempts = 5): DeliveryNote
    {
        $deliveryDate = \Carbon\Carbon::parse($data['delivery_date']);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['delivery_code'] = $this->generateDeliveryCode($companyId, $deliveryDate);

            try {
                return DeliveryNote::create($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'delivery_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Someone else just took this number — loop and try the next one.
            }
        }
    }

    /**
     * Saves an update to a draft Delivery Note. If delivery_date is
     * part of this update and genuinely changed, regenerates
     * delivery_code to match. Same reasoning as
     * GoodsReceiptNoteService::saveWithUniqueCode() — always safe
     * here since a Delivery Note can only ever be edited while draft.
     */
    private function saveWithUniqueCode(DeliveryNote $delivery, array $data, int $maxAttempts = 5): void
    {
        if (! array_key_exists('delivery_date', $data)) {
            $delivery->update($data);
            return;
        }

        $newDeliveryDate = \Carbon\Carbon::parse($data['delivery_date']);

        if ($newDeliveryDate->toDateString() === $delivery->delivery_date->toDateString()) {
            $delivery->update($data);
            return;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['delivery_code'] = $this->generateDeliveryCode($delivery->company_id, $newDeliveryDate, excludeDnId: $delivery->id);

            try {
                $delivery->update($data);
                return;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'delivery_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }
    }
}
