<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PurchaseReturnService
//
//  Audit H16 (Sept 2026) — goods sent back to a supplier against a
//  posted Goods Receipt Note. Before this, a receipt could only be
//  reopened as a whole (and not at all once billed or once any of the
//  stock had moved).
//
//  POSTING (one step — a return is posted when it is saved):
//    1. Stock out: StockLedgerService::issue() with the receipt's own
//       unit cost ('unit_cost_override') — the exact reverse of the
//       receipt, capped at the book value on hand. The remaining stock
//       is re-averaged. Movement type 'receipt_reversal'.
//    2. One journal entry:
//         Dr 233 GRNI                 qty × receipt cost
//         Cr Inventory                value that actually left stock
//         Cr/Dr product's COGS        any small difference (only when
//                                     the stock's average had drifted
//                                     below the receipt cost)
//    3. Quantity split per GRN line:
//       • NOT YET BILLED part — nothing more: the debit to 233 simply
//         cancels the GRNI credit the receipt created. The supplier
//         will bill only what was kept.
//       • ALREADY BILLED part — a Supplier Debit Note is raised and
//         posted automatically (latest bill first, at the bill's price
//         and VAT): Dr AP / Cr 233 at receipt cost, price difference to
//         COGS, Cr VAT Input. See SupplierDebitNoteService (goods lines).
//    4. The Purchase Order line's received quantity is reduced, so a
//       replacement delivery can be received against the same PO.
//
//  CANCEL — only when no debit note was raised: the stock comes back
//  in at the value that left, the entry is reversed, counters restored.
//
//  A GRN with returns cannot be reopened, nor a bill whose goods were
//  returned (GoodsReceiptNoteService / SupplierBillService guards).
//  Landed costs added to the receipt are NOT part of the return cost —
//  any landed cost on returned goods stays in stock (documented limit).
//
//  bcmath (Rule 4), AuditLog (Rule 5), company_id explicit (H9).
//
//  @layer    Service
//  @depends  PurchaseReturn, PurchaseReturnLine, GoodsReceiptNote,
//            GoodsReceiptNoteLine, PurchaseOrderLine, SupplierBillLine,
//            SupplierDebitNoteService, StockLedgerService,
//            JournalEntryService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use App\Models\SupplierBillLine;
use App\Models\SupplierDebitNote;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    public function __construct(
        private StockLedgerService $stockLedger,
        private JournalEntryService $journalEntryService,
        private SupplierDebitNoteService $debitNoteService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── Read ─────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return PurchaseReturn::where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('return_code', 'like', "%{$s}%"))
            ->with(['partner:id,name_en', 'goodsReceiptNote:id,grn_code', 'journalEntry:id,entry_number', 'lines.product:id,name_en,code'])
            ->orderByDesc('return_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();
    }

    /** Posted receipts that still hold something that can be sent back. */
    public function returnableReceipts(int $companyId): array
    {
        $open = fn ($q) => $q->whereRaw('returned_unbilled_quantity + returned_billed_quantity < quantity')
            ->whereHas('product', fn ($p) => $p->where('has_stock', true));

        return GoodsReceiptNote::where('company_id', $companyId)
            ->where('status', GoodsReceiptNote::STATUS_POSTED)
            ->whereHas('lines', $open)
            ->with(['partner:id,name_en', 'lines' => $open, 'lines.product:id,name_en,code'])
            ->orderByDesc('received_date')
            ->limit(300)
            ->get()
            ->map(fn ($grn) => [
                'id' => $grn->id, 'code' => $grn->grn_code, 'date' => $grn->received_date?->toDateString(),
                'partner' => $grn->partner?->name_en, 'location_id' => $grn->inventory_location_id,
                'lines' => $grn->lines->map(fn ($l) => [
                    'id' => $l->id, 'description' => $l->description, 'product' => $l->product?->name_en,
                    'unit_cost' => (string) $l->unit_cost,
                    'returnable' => bcsub(bcsub((string) $l->quantity, (string) $l->returned_unbilled_quantity, 4), (string) $l->returned_billed_quantity, 4),
                    'billed' => (string) $l->billed_quantity,
                ])->values(),
            ])->values()->all();
    }

    // ── Create (= post) ──────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): PurchaseReturn
    {
        $date = substr((string) $data['return_date'], 0, 10);

        // Same rule as receipts (Rule 26.5): no back-dated stock movement
        // once a newer fiscal year exists.
        if ($this->fiscalYearService->newerFiscalYearExists($companyId, $date)) {
            throw new \DomainException('This return date falls in a fiscal year that has since been superseded by a newer one — stock movements cannot be back-dated.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor, $date) {
            $grn = GoodsReceiptNote::where('company_id', $companyId)->lockForUpdate()->findOrFail($data['goods_receipt_note_id']);
            if (! $grn->isPosted()) {
                throw new \DomainException('Goods can only be returned against a posted Goods Receipt Note.');
            }
            if ($date < $grn->received_date->toDateString()) {
                throw new \DomainException('The return cannot be dated before the receipt ('.$grn->received_date->toDateString().').');
            }

            $return = PurchaseReturn::create([
                'company_id' => $companyId, 'return_code' => $this->nextCode($companyId, $date),
                'partner_id' => $grn->partner_id, 'goods_receipt_note_id' => $grn->id, 'return_date' => $date,
                'status' => PurchaseReturn::STATUS_POSTED, 'reason' => $data['reason'] ?? null, 'created_by' => $actor->id,
            ]);

            $grni = '0.00';
            $inventory = []; // account => credit
            $variance  = []; // COGS account => signed credit
            $toDebit   = []; // grn_line_id => billed quantity to debit
            $touchedPos = [];

            foreach ($data['lines'] as $input) {
                $qty = bcadd((string) $input['quantity'], '0', 4);
                if (bccomp($qty, '0', 4) <= 0) continue;

                $grnLine = GoodsReceiptNoteLine::where('goods_receipt_note_id', $grn->id)->lockForUpdate()->findOrFail($input['goods_receipt_note_line_id']);
                $product = $grnLine->product;
                if (! $product?->has_stock) {
                    throw new \DomainException("\"{$grnLine->description}\" is not a stock item — only stock items can be returned here.");
                }
                $returnable = bcsub(bcsub((string) $grnLine->quantity, (string) $grnLine->returned_unbilled_quantity, 4), (string) $grnLine->returned_billed_quantity, 4);
                if (bccomp($qty, $returnable, 4) > 0) {
                    throw new \DomainException("Only {$returnable} of \"{$grnLine->description}\" can still be returned.");
                }

                $unbilledAvail = bcsub(bcsub((string) $grnLine->quantity, (string) $grnLine->billed_quantity, 4), (string) $grnLine->returned_unbilled_quantity, 4);
                if (bccomp($unbilledAvail, '0', 4) < 0) $unbilledAvail = '0';
                $unbilled = bccomp($qty, $unbilledAvail, 4) <= 0 ? $qty : $unbilledAvail;
                $billed   = bcsub($qty, $unbilled, 4);

                $unitCost  = (string) $grnLine->unit_cost;
                $totalCost = $this->round2(bcmul($qty, $unitCost, 6));
                $location  = (int) ($input['location_id'] ?? $grn->inventory_location_id);
                $tagged    = $grnLine->project_id || $grnLine->sales_order_id;

                $movement = $this->stockLedger->issue($companyId, $product, $location, $qty, $actor, [
                    'movement_type'      => StockMovement::TYPE_RECEIPT_REVERSAL,
                    'unit_cost_override' => $unitCost,
                    'project_id'         => $grnLine->project_id,
                    'sales_order_id'     => $grnLine->sales_order_id,
                    'actual_unit_cost'   => $tagged ? $unitCost : null,
                    'reference_type'     => 'purchase_return_line',
                    'reference_id'       => $grnLine->id,
                    'movement_date'      => $date,
                    'notes'              => "Purchase Return {$return->return_code}",
                ]);
                $stockValue = (string) $movement->wac_total_value;

                $grni = bcadd($grni, $totalCost, 2);
                $inventory[$product->inventory_account_id] = bcadd($inventory[$product->inventory_account_id] ?? '0', $stockValue, 2);
                $diff = bcsub($totalCost, $stockValue, 2);
                if (bccomp($diff, '0', 2) !== 0) {
                    if (! $product->cogs_account_id) {
                        throw new \DomainException("Product {$product->code} has no COGS account assigned — cannot post.");
                    }
                    $variance[$product->cogs_account_id] = bcadd($variance[$product->cogs_account_id] ?? '0', $diff, 2);
                }

                if (bccomp($billed, '0', 4) > 0) {
                    $toDebit[$grnLine->id] = bcadd($toDebit[$grnLine->id] ?? '0', $billed, 4);
                }

                $grnLine->update([
                    'returned_unbilled_quantity' => bcadd((string) $grnLine->returned_unbilled_quantity, $unbilled, 4),
                    'returned_billed_quantity'   => bcadd((string) $grnLine->returned_billed_quantity, $billed, 4),
                ]);
                if ($grnLine->purchase_order_line_id) {
                    $poLine = PurchaseOrderLine::lockForUpdate()->find($grnLine->purchase_order_line_id);
                    $poLine?->update(['received_quantity' => bcsub((string) $poLine->received_quantity, $qty, 4)]);
                    if ($poLine) $touchedPos[$poLine->purchase_order_id] = true;
                }

                PurchaseReturnLine::create([
                    'company_id' => $companyId, 'purchase_return_id' => $return->id, 'goods_receipt_note_line_id' => $grnLine->id,
                    'product_id' => $product->id, 'location_id' => $location, 'quantity' => $qty, 'unit_cost' => $unitCost,
                    'total_cost' => $totalCost, 'stock_value' => $stockValue, 'unbilled_quantity' => $unbilled,
                    'billed_quantity' => $billed, 'stock_movement_id' => $movement->id,
                ]);
            }

            if ($return->lines()->count() === 0) {
                throw new \DomainException('Enter the quantity returned on at least one line.');
            }

            $label = "Purchase Return {$return->return_code} — {$grn->grn_code}";
            $lines = [['chart_of_account_id' => $this->account($companyId, '233'), 'debit' => $grni, 'credit' => '0', 'description' => $label.' — GRNI']];
            foreach ($inventory as $acc => $amt) {
                if (bccomp($amt, '0', 2) > 0) $lines[] = ['chart_of_account_id' => $acc, 'debit' => '0', 'credit' => $amt, 'description' => $label.' — out of stock'];
            }
            foreach ($variance as $acc => $amt) {
                $lines[] = bccomp($amt, '0', 2) > 0
                    ? ['chart_of_account_id' => $acc, 'debit' => '0', 'credit' => $amt, 'description' => $label.' — cost difference']
                    : ['chart_of_account_id' => $acc, 'debit' => ltrim($amt, '-'), 'credit' => '0', 'description' => $label.' — cost difference'];
            }
            $entry = $this->journalEntryService->post($companyId, $date, $lines, 'purchase_return', $return->id, $label, $actor);

            foreach (array_keys($touchedPos) as $poId) {
                $this->refreshPoReceiptStatus($poId);
            }

            $debitNoteIds = $toDebit === [] ? [] : $this->raiseDebitNotes($companyId, $toDebit, $date, $return, $actor);

            // The PO's billed amount (functional) drops by what was debited,
            // so a replacement delivery can be billed against it again.
            if ($debitNoteIds !== [] && $grn->purchase_order_id) {
                $debited = (string) SupplierDebitNote::whereIn('id', $debitNoteIds)->sum('subtotal_amount_functional');
                $po = PurchaseOrder::lockForUpdate()->find($grn->purchase_order_id);
                $po?->update(['billed_amount' => bcsub((string) $po->billed_amount, $debited, 2)]);
            }

            $return->update(['total_cost' => $grni, 'journal_entry_id' => $entry->id, 'debit_note_ids' => $debitNoteIds ?: null]);
            AuditLog::record($actor, 'purchase_return.posted', $return, null, $return->fresh('lines')->toArray());

            return $return->fresh(['lines', 'partner', 'goodsReceiptNote']);
        });
    }

    // ── Cancel ───────────────────────────────────────────────────

    public function cancel(int $companyId, PurchaseReturn $return, string $reason, User $actor): PurchaseReturn
    {
        abort_unless((int) $return->company_id === $companyId, 404);
        if ($return->status !== PurchaseReturn::STATUS_POSTED) {
            throw new \DomainException('This return is already cancelled.');
        }
        if (! empty($return->debit_note_ids)) {
            throw new \DomainException('This return raised a posted Supplier Debit Note, which cannot be undone — record a new receipt and bill instead.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling.');
        }

        $this->assertNothingPostedSince($companyId, $return);

        return DB::transaction(function () use ($companyId, $return, $reason, $actor) {
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            $before = $return->toArray();
            $touchedPos = [];

            foreach ($return->lines as $line) {
                $grnLine = GoodsReceiptNoteLine::lockForUpdate()->find($line->goods_receipt_note_line_id);
                $tagged = $grnLine && ($grnLine->project_id || $grnLine->sales_order_id);
                $unit = bccomp((string) $line->quantity, '0', 4) > 0 ? bcdiv((string) $line->stock_value, (string) $line->quantity, 4) : '0';

                $this->stockLedger->receive($companyId, $line->product, (int) $line->location_id, (string) $line->quantity, $unit, $actor, [
                    'movement_type'    => StockMovement::TYPE_RECEIPT,
                    'skip_date_sequence' => true, // Audit M-13: cancelling a return puts back what it took out
                    'total_value'      => (string) $line->stock_value,
                    'project_id'       => $grnLine?->project_id,
                    'sales_order_id'   => $grnLine?->sales_order_id,
                    'actual_unit_cost' => $tagged ? (string) $line->unit_cost : null,
                    'reference_type'   => 'purchase_return_line',
                    'reference_id'     => $line->goods_receipt_note_line_id,
                    'movement_date'    => $return->return_date->toDateString(),
                    'notes'            => "Cancelled Purchase Return {$return->return_code}",
                ]);

                $grnLine?->update([
                    'returned_unbilled_quantity' => bcsub((string) $grnLine->returned_unbilled_quantity, (string) $line->unbilled_quantity, 4),
                    'returned_billed_quantity'   => bcsub((string) $grnLine->returned_billed_quantity, (string) $line->billed_quantity, 4),
                ]);
                if ($grnLine?->purchase_order_line_id) {
                    $poLine = PurchaseOrderLine::lockForUpdate()->find($grnLine->purchase_order_line_id);
                    $poLine?->update(['received_quantity' => bcadd((string) $poLine->received_quantity, (string) $line->quantity, 4)]);
                    if ($poLine) $touchedPos[$poLine->purchase_order_id] = true;
                }
            }

            $je = $return->journal_entry_id ? JournalEntry::find($return->journal_entry_id) : null;
            if ($je && $je->status === JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($je, $actor, "Cancelled Purchase Return {$return->return_code} — {$reason}");
            }
            foreach (array_keys($touchedPos) as $poId) {
                $this->refreshPoReceiptStatus($poId);
            }

            $return->update(['status' => PurchaseReturn::STATUS_CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            AuditLog::record($actor, 'purchase_return.cancelled', $return, $before, $return->fresh()->toArray());

            return $return->fresh();
        });
    }

    // ── Private ──────────────────────────────────────────────────

    /** One Supplier Debit Note per bill, latest bill first, at the bill's own price and VAT. */
    private function raiseDebitNotes(int $companyId, array $toDebit, string $date, PurchaseReturn $return, User $actor): array
    {
        $byBill = [];
        foreach ($toDebit as $grnLineId => $qtyNeeded) {
            $billLines = SupplierBillLine::where('goods_receipt_note_line_id', $grnLineId)
                ->whereHas('supplierBill', fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))
                ->with('supplierBill:id,bill_date')
                ->get()
                ->sortByDesc(fn ($l) => $l->supplierBill->bill_date->toDateString().sprintf('%012d', $l->id));

            $remaining = $qtyNeeded;
            foreach ($billLines as $billLine) {
                if (bccomp($remaining, '0', 4) <= 0) break;
                $already = (string) DB::table('supplier_debit_note_lines as l')
                    ->join('supplier_debit_notes as n', 'n.id', '=', 'l.supplier_debit_note_id')
                    ->where('n.status', SupplierDebitNote::STATUS_POSTED)
                    ->where('l.supplier_bill_line_id', $billLine->id)
                    ->sum('l.quantity');
                $available = bcsub((string) $billLine->quantity, $already, 4);
                if (bccomp($available, '0', 4) <= 0) continue;

                $take = bccomp($remaining, $available, 4) <= 0 ? $remaining : $available;
                $byBill[$billLine->supplier_bill_id][] = [
                    'supplier_bill_line_id' => $billLine->id,
                    'description'           => $billLine->description.' — returned ('.$return->return_code.')',
                    'quantity'              => $take,
                    'unit_price'            => (string) $billLine->unit_price,
                    'vat_rate'              => (string) $billLine->vat_rate,
                ];
                $remaining = bcsub($remaining, $take, 4);
            }
            if (bccomp($remaining, '0', 4) > 0) {
                throw new \DomainException('Part of the returned quantity was billed but a Debit Note already covers it — return less, or check the bill.');
            }
        }

        $ids = [];
        foreach ($byBill as $billId => $lines) {
            $note = $this->debitNoteService->create($companyId, [
                'supplier_bill_id' => $billId, 'debit_date' => $date,
                'debit_reason' => "Goods returned — Purchase Return {$return->return_code}", 'lines' => $lines,
            ], $actor);
            try {
                $this->debitNoteService->post($note, $actor);
            } catch (\DomainException $e) {
                throw new \DomainException($e->getMessage().' The billed goods have already been paid for, so the supplier owes a cash refund — refunds are not automated yet. Agree the refund with the supplier and record it with a journal entry.', 0, $e);
            }
            $ids[] = $note->id;
        }

        return $ids;
    }

    /**
     * A return can be cancelled only while nothing has since been
     * received or billed on its lines — a replacement receipt or a new
     * bill reads the counters the return changed.
     */
    private function assertNothingPostedSince(int $companyId, PurchaseReturn $return): void
    {
        $since = (int) $return->journal_entry_id; // entry ids only ever grow
        $grnLineIds = $return->lines()->pluck('goods_receipt_note_line_id');
        $poLineIds = GoodsReceiptNoteLine::whereIn('id', $grnLineIds)->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id');

        $laterReceipt = $poLineIds->isNotEmpty() && GoodsReceiptNoteLine::whereIn('purchase_order_line_id', $poLineIds)
            ->whereHas('goodsReceiptNote', fn ($q) => $q->where('company_id', $companyId)->where('status', GoodsReceiptNote::STATUS_POSTED)->where('journal_entry_id', '>', $since))
            ->exists();
        $laterBill = SupplierBillLine::whereIn('goods_receipt_note_line_id', $grnLineIds)
            ->whereHas('supplierBill', fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->where('journal_entry_id', '>', $since))
            ->exists();

        if ($laterReceipt || $laterBill) {
            throw new \DomainException('A receipt or bill has been posted on these goods since the return — it can no longer be cancelled.');
        }
    }

    private function refreshPoReceiptStatus(int $poId): void
    {
        $po = PurchaseOrder::find($poId);
        if (! $po) return;
        $lines = $po->lines()->get();
        $any = $lines->contains(fn ($l) => bccomp((string) $l->received_quantity, '0', 4) > 0);
        $all = $lines->every(fn ($l) => $l->isFullyReceived());
        $po->update(['receipt_status' => $all ? 'fully_received' : ($any ? 'partially_received' : 'not_received')]);
    }

    private function nextCode(int $companyId, string $date): string
    {
        // Audit L-7: atomic yearly counter (code shape PR-YYYY-NNNNN kept).
        $year   = substr($date, 0, 4);
        $prefix = 'PR-'.$year.'-';
        $build  = fn (int $n) => $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $n      = \App\Support\DocumentNumber::nextNumber(
            $companyId, \App\Models\PurchaseReturn::class, 'return_code', 'PR', $year, $prefix.'%', $build, '-'
        );

        return $build($n);
    }

    private function account(int $companyId, string $code): int
    {
        return (int) (ChartOfAccount::findByCode($companyId, $code)?->id
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code}."));
    }

    private function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
