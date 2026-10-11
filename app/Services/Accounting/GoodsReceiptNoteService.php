<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GoodsReceiptNoteService
//
//  All business logic for Goods Receipt Notes — Accounting Engine
//  Phase 2, first module built on top of the Inventory Engine and
//  the new Journal Entry engine.
//
//  LIFECYCLE:
//    create()  — saves a DRAFT. No stock movement, no GL posting,
//                no effect on the parent PO yet. Fully editable
//                and deletable while draft.
//    post()    — the one irreversible action. For every line:
//                  1. StockLedgerService::receive() — physical stock
//                     + WAC, dual-valuation when project_id OR
//                     sales_order_id is set (2026-09 — broadened from
//                     project-only; see migration 000220)
//                  2. rolls the line's quantity into its parent PO
//                     line's received_quantity (when PO-based)
//                Then, once all lines are received:
//                  3. recalculates the parent PO's receipt_status
//                     from every one of its lines (not just the ones
//                     on this GRN — a PO can be received across many
//                     GRNs)
//                  4. posts ONE journal entry for the whole GRN:
//                       Dr <product's inventory_account_id>  (grouped)
//                       Cr 233 GRNI
//    setToDraft() — reopens a posted GRN back to draft, automatically
//                reversing all four effects above (see its own doc
//                comment for the two situations that block it —
//                an existing Supplier Bill, or stock that has already
//                moved on since receipt). Mirrors
//                CustomerPaymentService::setToDraft() closely. A
//                future "Goods Return" document remains the correct
//                tool once stock has genuinely moved on (out of
//                scope here).
//
//  GRN POLICY GATE (grn_policy, confirmed by Mahmoud): whether
//  purchase_order_id may be null is a per-company Super Admin
//  setting, enforced here as the actual source of truth — the Form
//  Request also checks it, but this service checks it again
//  independently, the same defense-in-depth already established for
//  cross-company FK checks (Rule 2.5) and StockLedgerService's own
//  belongs-to-company guards.
//
//  PARTIAL RECEIPTS (confirmed by Mahmoud): a single PO can be
//  received across multiple GRNs. Every posted GRN line against a
//  PO line adds to that PO line's received_quantity — never
//  replaces it. Over-receipt beyond the PO line's tolerance band
//  (migration 000074 / PurchaseOrderLine::toleranceBand()) is a hard
//  block — ReceiptToleranceExceededException.
//
//  CURRENCY (stated assumption — see migration 000100 header): GRN
//  unit costs are captured in functional currency. When PO-based,
//  the create()/update() form is expected to convert the PO's
//  foreign unit_price using the PO's exchange_rate before sending it
//  here; this service does not re-derive that conversion, it trusts
//  the functional-currency figure it's given, exactly like every
//  other already-converted monetary field elsewhere in the app.
//
//  @layer    Service
//  @depends  GoodsReceiptNote, GoodsReceiptNoteLine, PurchaseOrder,
//            PurchaseOrderLine, Product, Company, JournalEntryService,
//            StockLedgerService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Exceptions\Accounting\ReceiptToleranceExceededException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GoodsReceiptNoteService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private \App\Services\Inventory\StockLedgerService $stockLedgerService,
        private FiscalYearService $fiscalYearService,
        private FixedAssetService $fixedAssetService,
        private LandingCostService $landingCostService,
    ) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return GoodsReceiptNote::with(['partner:id,name_en,name_ar', 'purchaseOrder:id,po_code', 'inventoryLocation:id,code,name_en'])
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('grn_code', 'like', "%{$s}%")
                    ->orWhere('supplier_reference', 'like', "%{$s}%")
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $p) => $q->where('purchase_order_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Fixed Asset Receipts — the dedicated, simplified page (Accounting
     * → Fixed Assets → Receipts, confirmed with Mahmoud after Fixed
     * Asset Types got the same treatment). Same underlying
     * goods_receipt_notes table and posting engine — a receipt with
     * Fixed Asset lines IS a GoodsReceiptNote — this just filters the
     * list to only those that actually contain at least one, so the
     * dedicated page never shows an unrelated inventory receipt.
     */
    public function paginateFixedAssetReceipts(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return GoodsReceiptNote::with(['partner:id,name_en,name_ar', 'purchaseOrder:id,po_code', 'inventoryLocation:id,code,name_en'])
            ->whereHas('lines.product', fn ($q) => $q->where('nature', \App\Models\Product::NATURE_FIXED_ASSET))
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('grn_code', 'like', "%{$s}%")
                    ->orWhere('supplier_reference', 'like', "%{$s}%")
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) ────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): GoodsReceiptNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $this->assertGrnPolicy($companyId, $data['purchase_order_id'] ?? null);
            $this->assertPoConsistency($data['purchase_order_id'] ?? null, $data['partner_id'] ?? null, $data['lines'] ?? []);

            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $landingCostSelections = $data['landing_cost_selections'] ?? [];
            unset($data['landing_cost_selections']);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $data['status']     = GoodsReceiptNote::STATUS_DRAFT;
            $data['exchange_rate'] = $data['exchange_rate'] ?? $this->defaultExchangeRate($data['purchase_order_id'] ?? null);

            $grn = $this->createWithUniqueCode($companyId, $data);

            $this->syncLines($grn, $companyId, $lines);
            $this->syncLandingCostSelections($grn, $companyId, $landingCostSelections);
            $this->recalculateGrnValue($grn);

            AuditLog::record($actor, 'goods_receipt_note.created', $grn, null, $grn->fresh('lines')->toArray());

            return $grn->fresh(['partner', 'purchaseOrder', 'inventoryLocation', 'lines.product']);
        });
    }

    // ── Update (draft only) ───────────────────────────────────────

    public function update(int $companyId, GoodsReceiptNote $grn, array $data, User $actor): GoodsReceiptNote
    {
        if (! $grn->isDraft()) {
            throw new \DomainException('Only a draft Goods Receipt Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $grn, $data, $actor) {
            $before = $grn->toArray();

            $this->assertGrnPolicy($companyId, $data['purchase_order_id'] ?? $grn->purchase_order_id);
            $this->assertPoConsistency(
                array_key_exists('purchase_order_id', $data) ? $data['purchase_order_id'] : $grn->purchase_order_id,
                $data['partner_id'] ?? $grn->partner_id,
                $data['lines'] ?? null
            );

            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $data['updated_by'] = $actor->id;

            $landingCostSelections = $data['landing_cost_selections'] ?? [];
            unset($data['landing_cost_selections']);

            // A GRN can only ever be edited while draft (guarded
            // above), so — unlike Purchase Order's issuance_date,
            // which needed a lock check because a posted GRN could
            // already depend on it — it's always safe to regenerate
            // grn_code here if received_date genuinely changed.
            $this->saveWithUniqueCode($grn, $data);

            $this->syncLines($grn, $companyId, $lines);
            $this->syncLandingCostSelections($grn, $companyId, $landingCostSelections);
            $this->recalculateGrnValue($grn);

            AuditLog::record($actor, 'goods_receipt_note.updated', $grn, $before, $grn->fresh('lines')->toArray());

            return $grn->fresh(['partner', 'purchaseOrder', 'inventoryLocation', 'lines.product']);
        });
    }

    // ── Delete (draft only — no financial trace exists yet) ───────

    public function delete(GoodsReceiptNote $grn, User $actor): void
    {
        if (! $grn->isDraft()) {
            throw new \DomainException('Only a draft Goods Receipt Note can be deleted.');
        }

        DB::transaction(function () use ($grn, $actor) {
            AuditLog::record($actor, 'goods_receipt_note.deleted', $grn, $grn->toArray(), null);
            $grn->landingCostSelections()->delete();
            $grn->lines()->delete();
            $grn->delete();
        });
    }

    // ── Post — the one irreversible action ────────────────────────

    public function post(GoodsReceiptNote $grn, User $actor): GoodsReceiptNote
    {
        if (! $grn->isDraft()) {
            throw new \DomainException('Only a draft Goods Receipt Note can be posted.');
        }

        $grn->loadMissing('lines.product', 'lines.purchaseOrderLine', 'landingCostSelections');

        if ($grn->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Goods Receipt Note with no lines.');
        }

        // Rule 26.5 — inventory receipts are hard-blocked from posting
        // back-dated once a newer fiscal year already exists for this
        // company (mirrors Rule 26.4 on Customer Invoice — WAC and
        // stock-on-hand can't be reopened once a later FY has started).
        if ($this->fiscalYearService->newerFiscalYearExists($grn->company_id, $grn->received_date->toDateString())) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Goods Receipt Note [{$grn->grn_code}] — its received date ({$grn->received_date->toDateString()}) falls in a fiscal year that has since been superseded by a newer one. Inventory receipts cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
            );
        }

        return DB::transaction(function () use ($grn, $actor) {
            $before = $grn->toArray();

            $inventoryDebits = []; // [chart_of_account_id => running total]

            foreach ($grn->lines as $line) {
                $this->assertWithinTolerance($line);

                // ── Fixed Asset lines — separate path (Fixed Assets
                // module, Phase 1) ──────────────────────────────────
                // A Fixed Asset product is never in the WAC-pooled
                // stock ledger (Product::deriveHasStock() already
                // returns false for it) — instead it creates N
                // individual FixedAsset register rows (one per unit
                // of quantity, confirmed with Mahmoud) and debits the
                // product's OWN category account (111xxx), not a
                // generic inventory account. Still clears through the
                // same GRNI (233) as every other line on this receipt
                // — same journal entry, same mechanism, just a
                // different debit account.
                if ($line->product && $line->product->isFixedAsset()) {
                    $this->fixedAssetService->receiveFromGrnLine($line, $actor);

                    $accountId = $line->product->fixed_asset_account_id;
                    if (! $accountId) {
                        throw new \DomainException(
                            "Product {$line->product->code} has no Fixed Asset account assigned — cannot post."
                        );
                    }

                    $inventoryDebits[$accountId] = bcadd(
                        $inventoryDebits[$accountId] ?? '0',
                        (string) $line->line_total,
                        2
                    );

                    if ($line->purchase_order_line_id) {
                        $this->applyReceiptToPoLine($line);
                    }

                    continue;
                }

                $movement = $this->stockLedgerService->receive(
                    $grn->company_id,
                    $line->product,
                    $grn->inventory_location_id,
                    (string) $line->quantity,
                    (string) $line->unit_cost,
                    $actor,
                    [
                        'movement_type'    => 'receipt',
                        'reference_type'   => 'goods_receipt_note',
                        'reference_id'     => $grn->id,
                        'movement_date'    => $grn->received_date->toDateString(),
                        'project_id'       => $line->project_id,
                        'sales_order_id'   => $line->sales_order_id,
                        // 2026-09 fix — was project_id-only, which silently
                        // dropped actual-cost tracking for a receipt line
                        // tagged to a standalone Sales Order (no parent
                        // Project). See migration 000220 and
                        // StockLedgerService header for the full reasoning.
                        'actual_unit_cost' => ($line->project_id || $line->sales_order_id) ? (string) $line->unit_cost : null,
                    ]
                );

                // INV-006 fix (confirmed with Mahmoud, 2026-09) — this
                // line remembers exactly which stock_movements row its
                // receipt created, so setToDraft() can later check
                // whether anything has been issued/consumed for this
                // product since — the only reliable way to know a
                // reversal is safe (see StockLedgerService::
                // reverseReceipt()'s header for the full reasoning).
                $line->update(['stock_movement_id' => $movement->id]);

                $accountId = $line->product->inventory_account_id;
                if (! $accountId) {
                    throw new \DomainException(
                        "Product {$line->product->code} has no inventory account assigned — cannot post."
                    );
                }

                $inventoryDebits[$accountId] = bcadd(
                    $inventoryDebits[$accountId] ?? '0',
                    (string) $movement->wac_total_value,
                    2
                );

                if ($line->purchase_order_line_id) {
                    $this->applyReceiptToPoLine($line);
                }
            }

            // ── Landing Cost attachment (see
            //    VeroCFO_Landing_Cost_Design_v1_0.md) — reads this
            //    GRN's own saved picks (GrnLandingCostSelection),
            //    made while it was still a draft, and folds each
            //    one's allocation into the SAME $inventoryDebits
            //    bucket the goods lines above just built, exactly the
            //    way Fixed Asset variance already merges into this
            //    bucket a few lines up. GL-side value addition only —
            //    does not feed back into StockLedgerService's own WAC
            //    unit cost, same established precedent as
            //    SupplierBillService's price variance (a very similar
            //    "extra cost discovered after physical receipt" case)
            //    — confirmed consistent with existing behavior, not a
            //    new simplification invented here.
            $landingCostCredits = []; // [chart_of_account_id => amount]

            foreach ($grn->landingCostSelections as $selection) {
                $landingCost = \App\Models\LandingCost::where('company_id', $grn->company_id)
                    ->lockForUpdate()
                    ->find($selection->landing_cost_id);

                if (! $landingCost || ! $landingCost->isEligibleForAttachment()) {
                    throw new \DomainException(
                        "One of the Landing Costs picked on this receipt is no longer available (it may already be " .
                        'attached elsewhere or cancelled) — remove it from this Goods Receipt Note and pick another before posting.'
                    );
                }

                $manualAmounts = $selection->allocation_base === \App\Models\LandingCostAllocation::BASE_MANUAL
                    ? $this->translateManualAmountsToLineIds($grn, $selection->manual_amounts)
                    : null;

                $result = $this->landingCostService->attach(
                    $landingCost,
                    $grn->lines,
                    $selection->allocation_base,
                    $grn,
                    $actor,
                    $manualAmounts,
                );

                foreach ($result['inventory_buckets'] as $accountId => $amount) {
                    $inventoryDebits[$accountId] = bcadd($inventoryDebits[$accountId] ?? '0', $amount, 2);
                }

                $landingCostCredits[$result['clearing_account_id']] = bcadd(
                    $landingCostCredits[$result['clearing_account_id']] ?? '0',
                    $result['clearing_amount'],
                    2
                );
            }

            if ($grn->purchase_order_id) {
                $this->recalculatePoReceiptStatus($grn->purchaseOrder ?? PurchaseOrder::find($grn->purchase_order_id));
            }

            $journalEntry = $this->postGrnJournalEntry($grn, $inventoryDebits, $actor, $landingCostCredits);

            $grn->update([
                'status'          => GoodsReceiptNote::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'       => $actor->id,
                'posted_at'       => now(),
            ]);

            AuditLog::record($actor, 'goods_receipt_note.posted', $grn, $before, $grn->fresh()->toArray());

            return $grn->fresh(['partner', 'purchaseOrder', 'inventoryLocation', 'lines.product', 'journalEntry.lines']);
        });
    }

    // ── Set to Draft (reopen a posted GRN) ──────────────────────────

    /**
     * Reopens a posted Goods Receipt Note back to draft, so it can be
     * edited or deleted. Mirrors CustomerPaymentService::setToDraft()
     * — automatically reverses EVERYTHING the posting did (journal
     * entry, stock quantity/WAC, the parent PO's received_quantity
     * and receipt_status), never asking the user to manually undo
     * anything first.
     *
     * Blocked if:
     *   - it's not currently posted (nothing to reopen)
     *   - a Supplier Bill already exists against any line of this
     *     GRN — the 3-way match (PO → GRN → Bill) already relies on
     *     this receipt being real; reopening it would leave that
     *     Bill pointing at a receipt that no longer happened
     *   - reversing the stock would push quantity negative anywhere
     *     — meaning some of this receipt's stock has already been
     *     issued, transferred, or consumed onward since it arrived.
     *     There are no separate batches in this system (one pooled
     *     Weighted Average per product), so once that stock has
     *     moved on, this specific receipt can no longer be safely
     *     un-done — a future "Goods Return" document is the correct
     *     tool for that situation, not this one.
     *   - its accounting period is closed (Rule: closed periods
     *     allow no postings, no edits, no reversals)
     */
    public function setToDraft(GoodsReceiptNote $grn, User $actor): GoodsReceiptNote
    {
        if (! $grn->isPosted()) {
            throw new \DomainException('Only a posted Goods Receipt Note can be set back to draft.');
        }

        $this->assertNoSupplierBillAgainst($grn);

        // Audit H16 — goods from this receipt have been sent back on a
        // Purchase Return, which relies on this receipt's cost.
        if ($grn->lines()->where(fn ($q) => $q->where('returned_unbilled_quantity', '>', 0)->orWhere('returned_billed_quantity', '>', 0))->exists()) {
            throw new \DomainException('Goods from this receipt have been returned to the supplier — cancel the Purchase Return first, then reopen the receipt.');
        }

        if ($this->fiscalYearService->isPeriodClosed($grn->company_id, $grn->received_date->toDateString())) {
            throw new \DomainException('This Goods Receipt Note\'s accounting period is closed — it cannot be reopened.');
        }

        $grn->loadMissing('lines.product', 'lines.purchaseOrderLine', 'landingCosts');

        return DB::transaction(function () use ($grn, $actor) {
            $before = $grn->toArray();

            foreach ($grn->landingCosts as $landingCost) {
                $this->landingCostService->release($landingCost, $actor);
            }

            foreach ($grn->lines as $line) {
                $this->stockLedgerService->reverseReceipt(
                    $grn->company_id,
                    $line->product,
                    $grn->inventory_location_id,
                    (string) $line->quantity,
                    (string) $line->line_total,
                    $actor,
                    [
                        'project_id'          => $line->project_id,
                        'sales_order_id'      => $line->sales_order_id,
                        'reference_type'      => 'goods_receipt_note',
                        'reference_id'        => $grn->id,
                        'movement_date'       => $grn->received_date->toDateString(),
                        'original_movement_id' => $line->stock_movement_id,
                    ]
                );

                if ($line->purchase_order_line_id) {
                    $this->rollBackReceiptFromPoLine($line);
                }
            }

            if ($grn->purchase_order_id) {
                $this->recalculatePoReceiptStatus($grn->purchaseOrder ?? PurchaseOrder::find($grn->purchase_order_id));
            }

            if ($grn->journal_entry_id) {
                $entry = \App\Models\JournalEntry::findOrFail($grn->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$grn->grn_code);
            }

            $grn->update([
                'status'           => GoodsReceiptNote::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'goods_receipt_note.set_to_draft', $grn, $before, $grn->fresh()->toArray());

            return $grn->fresh(['partner', 'purchaseOrder', 'inventoryLocation', 'lines.product']);
        });
    }

    /** Mirror image of applyReceiptToPoLine() — subtracts instead of adds. */
    private function rollBackReceiptFromPoLine(GoodsReceiptNoteLine $line): void
    {
        $poLine = $line->purchaseOrderLine ?? PurchaseOrderLine::lockForUpdate()->find($line->purchase_order_line_id);

        $poLine->update([
            'received_quantity' => bcsub((string) $poLine->received_quantity, (string) $line->quantity, 4),
        ]);
    }

    /**
     * Blocks reopening a GRN once a Supplier Bill has been created
     * against any of its lines — see setToDraft()'s class comment
     * for why.
     */
    private function assertNoSupplierBillAgainst(GoodsReceiptNote $grn): void
    {
        $lineIds = $grn->lines()->pluck('id');

        $billed = SupplierBillLine::whereIn('goods_receipt_note_line_id', $lineIds)->exists();

        if ($billed) {
            throw new \DomainException(
                'A Supplier Bill already exists against this Goods Receipt Note — it cannot be reopened. ' .
                'The bill must be reversed first if this receipt genuinely needs correcting.'
            );
        }
    }

    // ── Cancel (draft only) ────────────────────────────────────────

    public function cancel(GoodsReceiptNote $grn, User $actor): GoodsReceiptNote
    {
        if (! $grn->isDraft()) {
            throw new \DomainException('Only a draft Goods Receipt Note can be cancelled — a posted GRN requires a Goods Return.');
        }

        return DB::transaction(function () use ($grn, $actor) {
            $before = $grn->toArray();
            $grn->update(['status' => GoodsReceiptNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'goods_receipt_note.cancelled', $grn, $before, $grn->fresh()->toArray());
            return $grn->fresh();
        });
    }

    // ── Private helpers ───────────────────────────────────────────

    private function assertGrnPolicy(int $companyId, ?int $purchaseOrderId): void
    {
        if ($purchaseOrderId !== null) {
            return; // PO-based GRN is always allowed regardless of policy
        }

        $company = Company::withoutGlobalScopes()->find($companyId);

        if (! $company?->allowsAdHocGrn()) {
            throw new \DomainException(
                'This company requires every Goods Receipt Note to be linked to a Purchase Order.'
            );
        }
    }

    private function assertWithinTolerance(GoodsReceiptNoteLine $line): void
    {
        if (! $line->purchase_order_line_id) {
            return; // ad-hoc line — no PO quantity to check against
        }

        $poLine = $line->purchaseOrderLine ?? PurchaseOrderLine::find($line->purchase_order_line_id);
        $maxReceivable = $poLine->maxReceivableQuantity();

        if (bccomp((string) $line->quantity, $maxReceivable, 4) > 0) {
            throw new ReceiptToleranceExceededException(
                "Receiving {$line->quantity} on this line would exceed the Purchase Order line's " .
                "tolerance — at most {$maxReceivable} more can still be received."
            );
        }
    }

    private function applyReceiptToPoLine(GoodsReceiptNoteLine $line): void
    {
        $poLine = $line->purchaseOrderLine ?? PurchaseOrderLine::lockForUpdate()->find($line->purchase_order_line_id);

        $poLine->update([
            'received_quantity' => bcadd((string) $poLine->received_quantity, (string) $line->quantity, 4),
        ]);
    }

    private function recalculatePoReceiptStatus(PurchaseOrder $po): void
    {
        $lines = $po->lines()->get();

        $anyReceived  = $lines->contains(fn ($l) => bccomp((string) $l->received_quantity, '0', 4) > 0);
        $allReceived  = $lines->every(fn ($l) => $l->isFullyReceived());

        $status = $allReceived ? 'fully_received' : ($anyReceived ? 'partially_received' : 'not_received');

        $po->update(['receipt_status' => $status]);
    }

    /**
     * One journal entry per GRN: Dr each distinct inventory account
     * involved (grouped, since a GRN can span raw materials +
     * finished goods etc.), Cr 233 GRNI for the total.
     */
    private function postGrnJournalEntry(GoodsReceiptNote $grn, array $inventoryDebits, User $actor, array $landingCostCredits = [])
    {
        $grniAccount = \App\Models\ChartOfAccount::findByCode($grn->company_id, '233');

        if (! $grniAccount) {
            throw new \DomainException('GRNI account (233) is not seeded for this company — cannot post.');
        }

        $total = '0';
        $lines = [];

        foreach ($inventoryDebits as $accountId => $amount) {
            $lines[] = [
                'chart_of_account_id' => $accountId,
                'debit'               => $amount,
                'credit'              => '0',
                'description'         => "Goods received — {$grn->grn_code}",
            ];
            $total = bcadd($total, $amount, 2);
        }

        // GRNI only ever equals the goods-only cost — Landing Cost
        // credits (below) are what account for the rest of $total.
        $grniAmount = $total;
        foreach ($landingCostCredits as $amount) {
            $grniAmount = bcsub($grniAmount, $amount, 2);
        }

        $lines[] = [
            'chart_of_account_id' => $grniAccount->id,
            'debit'               => '0',
            'credit'              => $grniAmount,
            'description'         => "Goods Received Not Invoiced — {$grn->grn_code}",
        ];

        foreach ($landingCostCredits as $accountId => $amount) {
            $lines[] = [
                'chart_of_account_id' => $accountId,
                'debit'               => '0',
                'credit'              => $amount,
                'description'         => "Landing Cost absorbed into inventory — {$grn->grn_code}",
            ];
        }

        return $this->journalEntryService->post(
            companyId: $grn->company_id,
            entryDate: $grn->received_date,
            lines: $lines,
            sourceType: 'goods_receipt_note',
            sourceId: $grn->id,
            narration: "Goods Receipt Note {$grn->grn_code}" . ($grn->purchaseOrder ? " against PO {$grn->purchaseOrder->po_code}" : ' (ad-hoc)'),
            actor: $actor
        );
    }

    /**
     * Bug fix (audit, Aug 2026, per Mahmoud): nothing here stopped the
     * same purchase_order_line_id from appearing on two or three lines
     * of the same GRN — each one separately allowed up to the PO
     * line's full max_receivable_quantity, so the form could silently
     * accept receiving several times what the PO actually allows.
     * Same class of bug already fixed once for Delivery Note's
     * (product, location) pairs (see DeliveryNoteService::syncLines())
     * — this is the frontend's dropdown filtering already caught
     * before submission (Form.vue's availablePoLinesFor()), checked
     * again here as the actual source of truth, the same
     * defense-in-depth already used throughout this app rather than
     * trusting the frontend alone.
     */
    private function syncLines(GoodsReceiptNote $grn, int $companyId, array $lines): void
    {
        $grn->lines()->delete();

        $seenPoLineIds = []; // purchase_order_line_id => true

        foreach ($lines as $i => $line) {
            $poLineId = $line['purchase_order_line_id'] ?? null;

            if ($poLineId) {
                if (isset($seenPoLineIds[$poLineId])) {
                    throw new \DomainException(
                        "This Purchase Order line is already on this Goods Receipt Note — increase " .
                        "that line's quantity instead of adding it a second time."
                    );
                }
                $seenPoLineIds[$poLineId] = true;
            }

            $lineTotal = bcmul((string) ($line['quantity'] ?? 0), (string) ($line['unit_cost'] ?? 0), 2);

            GoodsReceiptNoteLine::create([
                'company_id'             => $companyId,
                'goods_receipt_note_id'  => $grn->id,
                'purchase_order_line_id' => $poLineId,
                'product_id'             => $line['product_id'],
                'description'            => $line['description'] ?? null,
                'quantity'               => $line['quantity'],
                'uom_id'                 => $line['uom_id'] ?? null,
                'unit_cost'              => $line['unit_cost'],
                'line_total'             => $lineTotal,
                'project_id'             => $line['project_id'] ?? null,
                'sales_order_id'         => $line['sales_order_id'] ?? null,
                'sort_order'             => $i + 1,
            ]);
        }
    }

    /**
     * Manual Landing Cost allocation is keyed by LINE POSITION while a
     * GRN is still draft (0-indexed — see GoodsReceiptNotes/Form.vue),
     * because a not-yet-saved line has no real
     * goods_receipt_note_line_id to key by yet. This translates those
     * positional keys into the real, persisted line IDs — matched by
     * sort_order (1-indexed, position + 1) — the moment before
     * LandingCostService::attach() needs real IDs.
     */
    private function translateManualAmountsToLineIds(GoodsReceiptNote $grn, ?array $manualAmounts): ?array
    {
        if (! $manualAmounts) {
            return null;
        }

        $linesByPosition = $grn->lines->sortBy('sort_order')->values();

        $translated = [];
        foreach ($manualAmounts as $position => $amount) {
            $line = $linesByPosition->get((int) $position);
            if (! $line) {
                throw new \DomainException('A manually-allocated Landing Cost line no longer matches this receipt\'s lines — remove and re-pick it.');
            }
            $translated[$line->id] = $amount;
        }

        return $translated;
    }

    /**
     * Saves the draft's Landing Cost picks (see
     * VeroCFO_Landing_Cost_Design_v1_0.md and GrnLandingCostSelection's
     * header) — no allocation math runs here, and nothing about any
     * Landing Cost's own eligibility is touched. That only happens in
     * post(). Delete-then-recreate, same pattern as syncLines().
     */
    private function syncLandingCostSelections(GoodsReceiptNote $grn, int $companyId, array $selections): void
    {
        $grn->landingCostSelections()->delete();

        $seenLandingCostIds = [];

        foreach ($selections as $selection) {
            $landingCostId = $selection['landing_cost_id'];

            if (isset($seenLandingCostIds[$landingCostId])) {
                throw new \DomainException('This Landing Cost is already picked on this Goods Receipt Note.');
            }
            $seenLandingCostIds[$landingCostId] = true;

            \App\Models\GrnLandingCostSelection::create([
                'company_id'            => $companyId,
                'goods_receipt_note_id' => $grn->id,
                'landing_cost_id'       => $landingCostId,
                'allocation_base'       => $selection['allocation_base'],
                'manual_amounts'        => $selection['manual_amounts'] ?? null,
            ]);
        }
    }

    /**
     * Server-side source of truth for two rules the form also enforces:
     * the supplier on a GRN must be the Purchase Order's supplier, and every
     * line linked to a PO line must belong to THAT purchase order.
     * $lines null = lines are not being changed (nothing to check there).
     */
    private function assertPoConsistency($purchaseOrderId, $partnerId, ?array $lines): void
    {
        $poLineIds = collect($lines ?? [])->pluck('purchase_order_line_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();

        if (! $purchaseOrderId) {
            if ($poLineIds->isNotEmpty()) {
                throw new \DomainException('This receipt has no Purchase Order, so its lines cannot be linked to Purchase Order lines.');
            }
            return;
        }

        $po = PurchaseOrder::find($purchaseOrderId);
        if (! $po) {
            throw new \DomainException('Purchase Order not found.');
        }

        if ($partnerId && (int) $po->partner_id !== (int) $partnerId) {
            throw new \DomainException("The supplier must be the Purchase Order's supplier.");
        }

        if ($poLineIds->isNotEmpty()) {
            $belonging = PurchaseOrderLine::whereIn('id', $poLineIds)->where('purchase_order_id', $po->id)->count();
            if ($belonging !== $poLineIds->count()) {
                throw new \DomainException('One or more lines belong to a different Purchase Order. Remove them or pick the right order.');
            }
        }
    }

    private function recalculateGrnValue(GoodsReceiptNote $grn): void
    {
        $grn->update(['grn_value' => $grn->lines()->sum('line_total')]);
    }

    private function defaultExchangeRate(?int $purchaseOrderId): string
    {
        if (! $purchaseOrderId) {
            return '1';
        }

        $po = PurchaseOrder::find($purchaseOrderId);
        return $po ? (string) $po->exchange_rate : '1';
    }

    /**
     * grn_code's YYYY/MM comes from receivedDate — the GRN's real
     * business date, already the same field the journal entry posts
     * against — not from "now." The number is the next locked
     * sequence for that month, so a deleted receipt is never reused.
     */
    private function generateGrnCode(int $companyId, \Carbon\Carbon $receivedDate, ?int $excludeGrnId = null): string
    {
        // Audit L7: locked sequence for the receipt month. Deleting a receipt does not reuse its number.
        return \App\Support\DocumentNumber::code($companyId, GoodsReceiptNote::class, 'grn_code', 'GRN', $receivedDate);
    }

    /**
     * Saves a brand new Goods Receipt Note with a guaranteed-unique
     * grn_code. If two save attempts happen close together (a form
     * re-submit on a slow connection, two companies both posting
     * their first GRN in the same month), both can count the same
     * "next number" before either is actually saved — the second one
     * then fails with a "Duplicate entry" error from the database.
     * Caught here and automatically retried with the next number —
     * safe inside the transaction because MySQL does not abort a
     * transaction on a duplicate-key error the way some other
     * databases do.
     */
    private function createWithUniqueCode(int $companyId, array $data, int $maxAttempts = 5): GoodsReceiptNote
    {
        $receivedDate = \Carbon\Carbon::parse($data['received_date']);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['grn_code'] = $this->generateGrnCode($companyId, $receivedDate);

            try {
                return GoodsReceiptNote::create($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'grn_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Someone else just took this number — loop and try the next one.
            }
        }
    }

    /**
     * Saves an update to a draft GRN. If received_date is part of
     * this update and genuinely changed, regenerates grn_code to
     * match (always safe here — a GRN can only ever be edited while
     * draft, so nothing downstream could already depend on the old
     * number), retrying on a rare collision the same way
     * createWithUniqueCode() does.
     */
    private function saveWithUniqueCode(GoodsReceiptNote $grn, array $data, int $maxAttempts = 5): void
    {
        if (! array_key_exists('received_date', $data)) {
            $grn->update($data);
            return;
        }

        $newReceivedDate = \Carbon\Carbon::parse($data['received_date']);

        if ($newReceivedDate->toDateString() === $grn->received_date->toDateString()) {
            $grn->update($data);
            return;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['grn_code'] = $this->generateGrnCode($grn->company_id, $newReceivedDate, excludeGrnId: $grn->id);

            try {
                $grn->update($data);
                return;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'grn_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }
    }
}
