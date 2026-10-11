<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierBillService
//
//  All business logic for Supplier Bills — Accounting Engine Phase 2,
//  the module that completes Purchase Order → Goods Receipt Note →
//  Supplier Bill. See migrations 000105-000107 for the full design
//  rationale confirmed by Mahmoud. Summary of the three line shapes
//  a Bill can post (see SupplierBillLine header for the exact rules):
//
//    GOODS            Dr 233 GRNI (at the GRN's own recorded cost)
//                      Dr/Cr product's inventory account (variance)
//                      Dr/Cr product's COGS account (variance)
//    FRESH EXPENSE     Dr [user-picked expense/asset account]
//    ACCRUAL SETTLE    Dr 228 Accrued Expenses (via AccrualService)
//                      ── every line also adds its own VAT to 137 ──
//                      Cr 221001/221002 Accounts Payable (local/import,
//                      resolved from whether the Bill's currency is
//                      the company's functional currency)
//
//  PRICE VARIANCE (confirmed by Mahmoud): no dedicated variance
//  account. The difference between the Bill's actual unit price and
//  the GRN's recorded unit_cost splits between the product's own
//  inventory account and its COGS account, per line, via
//  `variance_inventory_pct` (default 100 — fully adjusts inventory,
//  Mahmoud's stated default since goods are often still partly in
//  stock). Every matched Purchase Order line is flagged via
//  PurchaseOrderLine::recordPriceVariance() — cumulative, visible,
//  never silently absorbed.
//
//  CURRENCY / FUNCTIONAL CONSISTENCY: every monetary figure that
//  actually posts to the GL is computed as
//  bcmul(bill-currency amount, bill.exchange_rate, 2) — applied
//  identically both when the header totals are (re)computed in
//  syncLines()/recalculateTotals() AND when the journal entry buckets
//  are built in post(). Using the exact same formula both places
//  (rather than re-deriving the header total independently) is what
//  guarantees the journal entry always balances to the cent — see
//  lineFunctionalAmount() below.
//
//  PO BILLED-AMOUNT TRACKING — a stated design decision: since a Bill
//  can be raised in a different currency than its Purchase Order,
//  `purchase_orders.billed_amount` is tracked here in FUNCTIONAL
//  currency (compared against po_value x po.exchange_rate), not in
//  the PO's own currency. This is the only cross-currency-safe way
//  to track it without a second conversion layer, and is flagged
//  here rather than silently redefining what the column holds.
//
//  DUPLICATE INVOICE CHECK — soft, not a DB unique (see migration
//  000106 header) — same partner + same non-null supplier_invoice_
//  number already on another non-cancelled Bill throws
//  DuplicateSupplierInvoiceException.
//
//  @layer    Service
//  @depends  SupplierBill, SupplierBillLine, GoodsReceiptNoteLine,
//            PurchaseOrder, PurchaseOrderLine, Accrual, ChartOfAccount,
//            Product, Company, CompanyActiveCurrency,
//            JournalEntryService, AccrualService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BillExceedsReceivedQuantityException;
use App\Exceptions\Accounting\DuplicateSupplierInvoiceException;
use App\Models\Accrual;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FixedAssetCostLine;
use App\Models\GoodsReceiptNoteLine;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierBillService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private AccrualService $accrualService,
        private SupplierPaymentService $supplierPaymentService,
        private FiscalYearService $fiscalYearService,
        private FixedAssetService $fixedAssetService,
    ) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SupplierBill::with(['partner:id,name_en,name_ar', 'purchaseOrder:id,po_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('bill_code', 'like', "%{$s}%")
                    ->orWhere('supplier_invoice_number', 'like', "%{$s}%")
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['bill_type'] ?? null, fn ($q, $t) => $q->where('bill_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) ────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $this->assertGrnLinesBelongToSupplier($companyId, (int) ($data['partner_id'] ?? 0), $lines);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $data['bill_code']  = $this->generateBillCode($companyId, $data['bill_date'] ?? null);
            $data['status']     = SupplierBill::STATUS_DRAFT;
            $data['exchange_rate'] = app(FxService::class)->resolveRate(
                $companyId,
                ($data['currency_id'] ?? null) ? (int) ($data['currency_id'] ?? null) : null,
                substr((string) ($data['bill_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1

            $bill = SupplierBill::create($data);

            $this->syncLines($bill, $companyId, $lines);
            $this->recalculateTotals($bill);

            AuditLog::record($actor, 'supplier_bill.created', $bill, null, $bill->fresh('lines')->toArray());

            return $bill->fresh(['partner', 'purchaseOrder', 'currency', 'lines']);
        });
    }

    // ── Update (draft only) ───────────────────────────────────────

    public function update(int $companyId, SupplierBill $bill, array $data, User $actor): SupplierBill
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only a draft Supplier Bill can be edited.');
        }

        return DB::transaction(function () use ($companyId, $bill, $data, $actor) {
            $before = $bill->toArray();

            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $this->assertGrnLinesBelongToSupplier($companyId, (int) ($data['partner_id'] ?? $bill->partner_id), $lines);

            $data['updated_by'] = $actor->id;
            // Audit C8: a foreign bill saved without a rate takes the
            // rate table's, never a silent 1.
            if (array_key_exists('exchange_rate', $data) || array_key_exists('currency_id', $data)) {
                $currencyId = (int) ($data['currency_id'] ?? $bill->currency_id);
                $data['exchange_rate'] = app(FxService::class)->resolveRate(
                    $companyId, $currencyId ?: null,
                    substr((string) ($data['bill_date'] ?? $bill->bill_date), 0, 10),
                    $data['exchange_rate'] ?? null
                );
            }

            $bill->update($data);

            $this->syncLines($bill, $companyId, $lines);
            $this->recalculateTotals($bill);

            AuditLog::record($actor, 'supplier_bill.updated', $bill, $before, $bill->fresh('lines')->toArray());

            return $bill->fresh(['partner', 'purchaseOrder', 'currency', 'lines']);
        });
    }

    // ── Delete (draft only) ────────────────────────────────────────

    public function delete(SupplierBill $bill, User $actor): void
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only a draft Supplier Bill can be deleted.');
        }

        DB::transaction(function () use ($bill, $actor) {
            AuditLog::record($actor, 'supplier_bill.deleted', $bill, $bill->toArray(), null);
            $bill->lines()->delete();
            $bill->delete();
        });
    }

    // ── Post — the one irreversible action ────────────────────────

    public function post(SupplierBill $bill, User $actor): SupplierBill
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only a draft Supplier Bill can be posted.');
        }

        $bill->loadMissing('lines.goodsReceiptNoteLine.product', 'lines.goodsReceiptNoteLine.purchaseOrderLine', 'lines.accrual', 'partner.paymentTerm.lines');

        if ($bill->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Supplier Bill with no lines.');
        }

        return DB::transaction(function () use ($bill, $actor) {
            $before = $bill->toArray();

            $this->assertNotDuplicateInvoice($bill);

            $debitBuckets      = []; // chart_of_account_id => signed functional amount
            $grnIncrements     = []; // grn_line_id => quantity to add to billed_quantity
            $poBilledIncrements = []; // purchase_order_id => functional amount to add to billed_amount
            $poIdsTouchedByVariance = [];

            foreach ($bill->lines as $line) {
                if ($bill->isPrepaidBill()) {
                    // Prepaid Expense Bill lines are always fresh-expense-shaped
                    // (chart_of_account_id set, no GRN/accrual) — the bill's own
                    // bill_type is what routes them here, not a per-line flag.
                    // See migration 000192 header.
                    $this->postPrepaidExpenseLine($bill, $line, $debitBuckets);
                } elseif ($line->isGoodsLine()) {
                    $this->postGoodsLine($bill, $line, $debitBuckets, $grnIncrements, $poBilledIncrements, $poIdsTouchedByVariance, $actor);
                } elseif ($line->isAccrualSettlement()) {
                    $this->postAccrualSettlementLine($bill, $line, $debitBuckets, $actor);
                } else {
                    $this->postFreshExpenseLine($bill, $line, $debitBuckets);
                }

                $this->addLineVat($bill, $line, $debitBuckets);
            }

            foreach ($grnIncrements as $grnLineId => $qty) {
                $grnLine = GoodsReceiptNoteLine::lockForUpdate()->find($grnLineId);
                $grnLine->update(['billed_quantity' => bcadd((string) $grnLine->billed_quantity, $qty, 4)]);
            }

            foreach ($poBilledIncrements as $poId => $amount) {
                $this->incrementPoBilledAmount((int) $poId, $amount);
            }

            foreach (array_unique($poIdsTouchedByVariance) as $poId) {
                $this->recalculatePoVarianceRollup((int) $poId);
            }

            $journalEntry = $this->postBillJournalEntry($bill, $debitBuckets, $actor);

            $dueDate = $this->calculateDueDate($bill);

            // Fix: amount_paid/amount_outstanding never got set here —
            // Supplier Payment needs these to settle bills at all.
            // amount_outstanding is the NET AP amount (post-WHT), same
            // figure this method just credited to Accounts Payable
            // above — never the gross bill_value_functional, since the
            // withheld portion was never owed to the supplier.
            $bill->update([
                'status'              => SupplierBill::STATUS_POSTED,
                'journal_entry_id'    => $journalEntry->id,
                'posted_by'           => $actor->id,
                'posted_at'           => now(),
                'amount_paid'         => '0',
                'amount_outstanding'  => bcsub((string) $bill->bill_value_functional, (string) $bill->withholding_tax_amount_functional, 2),
                'payment_term_id'     => $bill->partner->payment_term_id,
                'original_due_date'   => $dueDate,
                'due_date'            => $dueDate,
            ]);

            AuditLog::record($actor, 'supplier_bill.posted', $bill, $before, $bill->fresh()->toArray());

            return $bill->fresh(['partner', 'purchaseOrder', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    // ── Cancel (draft only) ─────────────────────────────────────────

    public function cancel(SupplierBill $bill, User $actor): SupplierBill
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only a draft Supplier Bill can be cancelled — a posted Bill requires a Debit Note.');
        }

        return DB::transaction(function () use ($bill, $actor) {
            $before = $bill->toArray();
            $bill->update(['status' => SupplierBill::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'supplier_bill.cancelled', $bill, $before, $bill->fresh()->toArray());
            return $bill->fresh();
        });
    }

    // ── Set to Draft (reopen a posted bill) ──────────────────────────

    /**
     * Reopens a posted Supplier Bill back to draft — the payable-side
     * mirror of CustomerInvoiceService::setToDraft(), built the same
     * way from day one (unlike the customer side, which had to be
     * fixed after the fact): any linked Supplier Payment allocation
     * that was part of a payment's original posting goes through
     * releaseOriginalAllocation() — never a full setToDraft() on the
     * payment — so the payment's status and its cheque (Drawee Bank,
     * Due Date, Cheque Number, Receiving Safe, whatever state it's
     * in) are never touched, only this bill's own link to it.
     */
    public function setToDraft(SupplierBill $bill, User $actor): SupplierBill
    {
        // Audit C7: an opening-balance bill can never become a draft —
        // correct it with a Supplier Credit / Debit Note instead.
        if ($bill->is_opening_balance) {
            throw new \DomainException('This is an opening-balance bill — it cannot be set back to draft. Use a Supplier Credit Note or Debit Note to correct it.');
        }
        if (! $bill->isPosted()) {
            throw new \DomainException('Only a posted Supplier Bill can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($bill->company_id, (string) $bill->bill_date)) {
            throw new \DomainException('This bill\'s accounting period is closed — it cannot be reopened.');
        }

        // Audit H16 (Sept 2026) — a posted Debit Note, or a Purchase Return
        // of goods already billed here, relies on this bill as it stands.
        if ($bill->debitNotes()->where('status', \App\Models\SupplierDebitNote::STATUS_POSTED)->exists()) {
            throw new \DomainException('A posted Debit Note exists against this bill — it cannot be reopened. Correct it with a Supplier Credit or Debit Note instead.');
        }
        $grnLineIds = $bill->lines()->whereNotNull('goods_receipt_note_line_id')->pluck('goods_receipt_note_line_id');
        if ($grnLineIds->isNotEmpty() && \App\Models\GoodsReceiptNoteLine::whereIn('id', $grnLineIds)->where('returned_billed_quantity', '>', 0)->exists()) {
            throw new \DomainException('Goods billed on this bill have been returned to the supplier — it cannot be reopened.');
        }

        // Block BEFORE touching anything, same reasoning as Customer
        // Invoice: a payment's original entry that also settled OTHER
        // bills can't be safely split apart here — reopen the payment
        // itself first.
        $bill->loadMissing('allocations.supplierPayment');
        foreach ($bill->allocations as $allocation) {
            $payment = $allocation->supplierPayment;
            $isOriginalPosting = $allocation->journal_entry_id && $payment && $allocation->journal_entry_id === $payment->journal_entry_id;

            if ($isOriginalPosting) {
                $touchesOtherBills = SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)
                    ->where('supplier_bill_id', '!=', $bill->id)
                    ->where('journal_entry_id', $payment->journal_entry_id)
                    ->exists();

                if ($touchesOtherBills) {
                    throw new \DomainException(
                        "This bill was settled as part of Payment {$payment->payment_code}'s original posting, which also settled other bills — reopen that payment first (Set to Draft), then re-save it without this bill, before this bill can be reopened."
                    );
                }
            }
        }

        // Prepaid Expense Bill — block, don't cascade. Same reasoning
        // as the Fixed Asset block just below: a posted amortization
        // entry may already be sitting inside financials reported to
        // someone. Confirmed with Mahmoud: the user reverses each
        // posted period themselves (oldest first) via
        // PrepaidAmortizationService::reverseEntry() — itself blocked
        // by a closed period, same protection inherited for free —
        // before this bill can be reopened. Once zero posted periods
        // remain there's nothing further to undo here: no schedule was
        // ever pre-generated (see PrepaidAmortizationService header),
        // so reversing the bill's own Dr 136/Cr 221 entry below is
        // already the whole story.
        if ($bill->isPrepaidBill()) {
            $postedCount = \App\Models\PrepaidAmortizationEntry::whereIn('supplier_bill_line_id', $bill->lines->pluck('id'))
                ->where('status', 'posted')
                ->count();

            if ($postedCount > 0) {
                $plural = $postedCount === 1 ? 'entry has' : 'entries have';
                $pronoun = $postedCount === 1 ? 'it' : 'them';
                throw new \DomainException(
                    "{$postedCount} amortization {$plural} already posted for this prepayment — reverse {$pronoun} first before setting this bill to draft."
                );
            }
        }

        // Fixed Assets module, Phase 1 — blocked, not half-handled.
        // There is currently no recorded link tracing exactly WHICH
        // FixedAssetCostLine(s) this specific bill confirmed (see
        // confirmMatchedFixedAssetCostLines() — it locates them by
        // GRN line + provisional status at post() time, not by a
        // stored reference back to this bill), so a blind reversal
        // here could desync the Asset Register from the GL — no
        // reliable way to know which cost lines to flip back to
        // provisional, or which depreciation corrections to undo.
        // Same "block rather than guess" principle already used by
        // GoodsReceiptNoteService::setToDraft() for its own similarly
        // irreversible situations. A future refinement can add that
        // traceability column and lift this block.
        $bill->loadMissing('lines.goodsReceiptNoteLine.product');
        foreach ($bill->lines as $line) {
            if ($line->isGoodsLine()) {
                $grnLine = $line->goodsReceiptNoteLine;
                if ($grnLine && $grnLine->product && $grnLine->product->isFixedAsset()) {
                    throw new \DomainException(
                        "This bill includes a Fixed Asset line ({$grnLine->product->code}) — it cannot be reopened to draft in this phase. " .
                        'Correct the asset\'s cost directly from the Fixed Asset Register instead, or contact support for a manual correction.'
                    );
                }
            }
        }

        $bill->loadMissing('lines.goodsReceiptNoteLine.purchaseOrderLine', 'lines.accrual');

        return DB::transaction(function () use ($bill, $actor) {
            $before = $bill->toArray();

            foreach ($bill->allocations as $allocation) {
                $payment = $allocation->supplierPayment;
                $isOriginalPosting = $allocation->journal_entry_id && $payment && $allocation->journal_entry_id === $payment->journal_entry_id;

                if ($isOriginalPosting) {
                    $this->supplierPaymentService->releaseOriginalAllocation($allocation, $actor);
                } else {
                    $this->supplierPaymentService->unallocate($allocation, $actor);
                }
            }

            if ($bill->journal_entry_id) {
                $entry = JournalEntry::findOrFail($bill->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$bill->bill_code);
            }

            $poIdsTouchedByVariance = [];

            foreach ($bill->lines as $line) {
                if ($line->isGoodsLine()) {
                    $grnLine = $line->goodsReceiptNoteLine ?? GoodsReceiptNoteLine::lockForUpdate()->find($line->goods_receipt_note_line_id);

                    if ($grnLine) {
                        $grnLine->update([
                            'billed_quantity' => bcsub((string) $grnLine->billed_quantity, (string) $line->quantity, 4),
                        ]);

                        if ($grnLine->purchase_order_line_id) {
                            $poLine = $grnLine->purchaseOrderLine ?? PurchaseOrderLine::find($grnLine->purchase_order_line_id);

                            if ($poLine) {
                                $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);
                                $this->decrementPoBilledAmount($poLine->purchase_order_id, $lineSubtotalFunctional);

                                if (bccomp((string) $line->variance_amount, '0', 2) !== 0) {
                                    $poLine->recordPriceVariance(
                                        bcmul((string) $line->variance_amount, '-1', 2),
                                        "Reopened {$bill->bill_code} — price variance reversed"
                                    );
                                    $poIdsTouchedByVariance[] = $poLine->purchase_order_id;
                                }
                            }
                        }
                    }
                } elseif ($line->isAccrualSettlement()) {
                    $accrual = $line->accrual ?? Accrual::lockForUpdate()->find($line->accrual_id);

                    if ($accrual) {
                        $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);
                        $newSettled = bcsub((string) $accrual->settled_amount, $lineSubtotalFunctional, 2);
                        if (bccomp($newSettled, '0', 2) < 0) {
                            $newSettled = '0';
                        }

                        $accrual->update([
                            'settled_amount' => $newSettled,
                            'status'         => bccomp($newSettled, '0', 2) <= 0
                                ? Accrual::STATUS_OPEN
                                : Accrual::STATUS_PARTIALLY_SETTLED,
                        ]);
                    }
                }

                $line->update(['variance_amount' => 0]);
            }

            foreach (array_unique($poIdsTouchedByVariance) as $poId) {
                $this->recalculatePoVarianceRollup((int) $poId);
            }

            $bill->update([
                'status'             => SupplierBill::STATUS_DRAFT,
                'journal_entry_id'   => null,
                'posted_by'          => null,
                'posted_at'          => null,
                'amount_paid'        => 0,
                'amount_outstanding' => 0,
                'original_due_date'  => null, // re-computed fresh next time this bill actually posts
                'due_date'           => null,
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'supplier_bill.set_to_draft', $bill, $before, $bill->fresh()->toArray());

            return $bill->fresh(['partner', 'purchaseOrder', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    /** Mirror of incrementPoBilledAmount() — same status thresholds, opposite direction. Never lets billed_amount go negative. */
    private function decrementPoBilledAmount(int $poId, string $amountFunctional): void
    {
        $po = PurchaseOrder::lockForUpdate()->find($poId);
        if (! $po) {
            return;
        }

        $poValueFunctional = bcmul((string) $po->po_value, (string) $po->exchange_rate, 2);
        $newBilled = bcsub((string) $po->billed_amount, $amountFunctional, 2);
        if (bccomp($newBilled, '0', 2) < 0) {
            $newBilled = '0';
        }

        $status = match (true) {
            bccomp($newBilled, $poValueFunctional, 2) >= 0 => 'fully_billed',
            bccomp($newBilled, '0', 2) > 0                 => 'partially_billed',
            default                                         => 'not_billed',
        };

        $po->update(['billed_amount' => $newBilled, 'billing_status' => $status]);
    }

    // ── Private — line shape handlers ────────────────────────────────

    private function postGoodsLine(
        SupplierBill $bill,
        SupplierBillLine $line,
        array &$debitBuckets,
        array &$grnIncrements,
        array &$poBilledIncrements,
        array &$poIdsTouchedByVariance,
        User $actor
    ): void {
        $grnLine = $line->goodsReceiptNoteLine ?? GoodsReceiptNoteLine::lockForUpdate()->find($line->goods_receipt_note_line_id);

        if (! $grnLine) {
            throw new \DomainException('Goods line references a Goods Receipt Note line that no longer exists.');
        }

        $remaining = $grnLine->remainingBillableQuantity();
        if (bccomp((string) $line->quantity, $remaining, 4) > 0) {
            throw new BillExceedsReceivedQuantityException(
                "Billing {$line->quantity} on this line would exceed the Goods Receipt Note line's remaining " .
                "billable quantity of {$remaining}."
            );
        }

        $grniAccount = ChartOfAccount::findByCode($bill->company_id, '233');
        if (! $grniAccount) {
            throw new \DomainException('GRNI account (233) is not seeded for this company — cannot post.');
        }

        $grniAmount = bcmul((string) $grnLine->unit_cost, (string) $line->quantity, 2); // already functional — GRN's own convention
        $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);
        $variance = bcsub($lineSubtotalFunctional, $grniAmount, 2);

        $this->addToBucket($debitBuckets, $grniAccount->id, $grniAmount);

        // ── Fixed Asset lines — separate variance path (Fixed Assets
        // module, Phase 1) ──────────────────────────────────────────
        // Unlike inventory (where a price variance splits between
        // inventory/COGS by variance_inventory_pct, because many
        // pooled identical units blend the difference into one WAC),
        // a Fixed Asset is one specific, identifiable thing — the
        // FULL variance adjusts THAT asset's own cost basis, never a
        // COGS split. Confirmed with Mahmoud.
        if ($grnLine->product && $grnLine->product->isFixedAsset()) {
            $this->postFixedAssetVariance($bill, $line, $grnLine, $variance, $debitBuckets, $actor);

            if ($grnLine->purchase_order_line_id) {
                $poLine = $grnLine->purchaseOrderLine ?? PurchaseOrderLine::find($grnLine->purchase_order_line_id);
                $poBilledIncrements[$poLine->purchase_order_id] = bcadd(
                    $poBilledIncrements[$poLine->purchase_order_id] ?? '0',
                    $lineSubtotalFunctional,
                    2
                );
            }

            $grnIncrements[$grnLine->id] = bcadd($grnIncrements[$grnLine->id] ?? '0', (string) $line->quantity, 4);

            return;
        }

        if (bccomp($variance, '0', 2) !== 0) {
            $product = $grnLine->product;
            $pct = (string) $line->variance_inventory_pct;
            $toInventory = bcdiv(bcmul($variance, $pct, 4), '100', 2);
            $toCogs = bcsub($variance, $toInventory, 2);

            if (bccomp($toInventory, '0', 2) !== 0) {
                if (! $product->inventory_account_id) {
                    throw new \DomainException("Product {$product->code} has no inventory account assigned — cannot post price variance.");
                }
                $this->addToBucket($debitBuckets, $product->inventory_account_id, $toInventory);
            }

            if (bccomp($toCogs, '0', 2) !== 0) {
                if (! $product->cogs_account_id) {
                    throw new \DomainException("Product {$product->code} has no COGS account assigned — cannot post price variance.");
                }
                $this->addToBucket($debitBuckets, $product->cogs_account_id, $toCogs);
            }

            $line->update(['variance_amount' => $variance]);

            if ($grnLine->purchase_order_line_id) {
                $poLine = $grnLine->purchaseOrderLine ?? \App\Models\PurchaseOrderLine::find($grnLine->purchase_order_line_id);
                $poLine->recordPriceVariance(
                    $variance,
                    "{$bill->bill_code}: GRN cost " . bcmul((string) $grnLine->unit_cost, '1', 2) .
                        " vs Bill price " . bcdiv($lineSubtotalFunctional, (string) $line->quantity, 2) .
                        " (" . ($variance >= 0 ? '+' : '') . "{$variance})"
                );
                $poIdsTouchedByVariance[] = $poLine->purchase_order_id;
            }
        }

        if ($grnLine->purchase_order_line_id) {
            $poLine = $grnLine->purchaseOrderLine ?? \App\Models\PurchaseOrderLine::find($grnLine->purchase_order_line_id);
            $poBilledIncrements[$poLine->purchase_order_id] = bcadd(
                $poBilledIncrements[$poLine->purchase_order_id] ?? '0',
                $lineSubtotalFunctional,
                2
            );
        }

        $grnIncrements[$grnLine->id] = bcadd($grnIncrements[$grnLine->id] ?? '0', (string) $line->quantity, 4);
    }

    /**
     * Fixed Asset variance handling (Fixed Assets module, Phase 1).
     * Confirms the matched cost line(s) at the Bill's actual amount —
     * always, even when variance is exactly zero, so the Asset
     * Register correctly reflects "this cost is now final" — and, if
     * there IS a variance, debits/credits the product's Fixed Asset
     * account for the full amount (never split to an expense account
     * the way inventory splits to COGS — a Fixed Asset is one
     * specific, identifiable thing, confirmed by Mahmoud).
     */
    private function postFixedAssetVariance(
        SupplierBill $bill,
        SupplierBillLine $line,
        GoodsReceiptNoteLine $grnLine,
        string $variance,
        array &$debitBuckets,
        User $actor
    ): void {
        $this->confirmMatchedFixedAssetCostLines($grnLine, (int) $line->quantity, $variance, $actor);

        if (bccomp($variance, '0', 2) !== 0) {
            $product = $grnLine->product;
            if (! $product->fixed_asset_account_id) {
                throw new \DomainException("Product {$product->code} has no Fixed Asset account assigned — cannot post price variance.");
            }
            $this->addToBucket($debitBuckets, $product->fixed_asset_account_id, $variance);
        }
    }

    /**
     * Finds the still-provisional FixedAssetCostLine rows this GRN
     * line created — one per unit, see
     * FixedAssetService::receiveFromGrnLine() — up to the quantity
     * being billed now, and confirms each at its own share of the
     * Bill's actual cost. $totalVariance is distributed proportionally
     * by each matched line's own provisional amount; the LAST match
     * absorbs any rounding residue, same principle used throughout
     * this codebase for period/tier rounding (see e.g.
     * LeaseAmortizationService::postMonthlyWearDown()).
     *
     * A partial bill (billing only some of a multi-unit GRN line's
     * quantity, the rest billed later on a separate Bill) still works
     * correctly here — it simply confirms fewer cost lines this time,
     * leaving the rest provisional for their own future Bill.
     */
    private function confirmMatchedFixedAssetCostLines(GoodsReceiptNoteLine $grnLine, int $quantityBilled, string $totalVariance, User $actor): void
    {
        $costLines = FixedAssetCostLine::where('goods_receipt_note_line_id', $grnLine->id)
            ->where('is_provisional', true)
            ->orderBy('id')
            ->limit($quantityBilled)
            ->get();

        if ($costLines->count() < $quantityBilled) {
            throw new \DomainException(
                "Expected {$quantityBilled} unconfirmed Fixed Asset cost line(s) for Goods Receipt Note line #{$grnLine->id}, found only {$costLines->count()} — cannot match this Bill."
            );
        }

        $totalProvisional = (string) $costLines->sum('amount');
        $allocated = '0.00';
        $count = $costLines->count();

        $costLines->values()->each(function (FixedAssetCostLine $costLine, int $index) use ($totalVariance, $totalProvisional, &$allocated, $count, $actor) {
            $isLast = $index === $count - 1;

            if ($isLast) {
                $share = bcsub($totalVariance, $allocated, 2);
            } else {
                $share = bccomp($totalProvisional, '0', 2) === 0
                    ? '0.00'
                    : bcdiv(bcmul($totalVariance, (string) $costLine->amount, 4), $totalProvisional, 2);
                $allocated = bcadd($allocated, $share, 2);
            }

            $confirmedAmount = bcadd((string) $costLine->amount, $share, 2);
            $this->fixedAssetService->confirmCostLine($costLine, $confirmedAmount, $actor);
        });
    }

    private function postAccrualSettlementLine(SupplierBill $bill, SupplierBillLine $line, array &$debitBuckets, User $actor): void
    {
        $accrual = $line->accrual ?? Accrual::lockForUpdate()->find($line->accrual_id);
        if (! $accrual) {
            throw new \DomainException('Accrual-settlement line references an accrual that no longer exists.');
        }

        $accruedAccount = ChartOfAccount::findByCode($bill->company_id, '228');
        if (! $accruedAccount) {
            throw new \DomainException('Accrued Expenses account (228) is not seeded for this company — cannot post.');
        }

        $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);

        $this->accrualService->applySettlement($accrual, $lineSubtotalFunctional, $actor);
        $this->addToBucket($debitBuckets, $accruedAccount->id, $lineSubtotalFunctional);
    }

    private function postFreshExpenseLine(SupplierBill $bill, SupplierBillLine $line, array &$debitBuckets): void
    {
        if (! $line->chart_of_account_id) {
            throw new \DomainException('An expense line must have an account selected.');
        }

        $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);
        $this->addToBucket($debitBuckets, $line->chart_of_account_id, $lineSubtotalFunctional);
    }

    /**
     * Prepaid Expense Bill line — identical to a fresh expense line
     * except the immediate debit goes to 136 Prepaid Expenses, never
     * the line's own chart_of_account_id. That account isn't wasted —
     * PrepaidAmortizationService reads it back off this same line as
     * the destination for each period's amortization. See migration
     * 000192 header.
     */
    private function postPrepaidExpenseLine(SupplierBill $bill, SupplierBillLine $line, array &$debitBuckets): void
    {
        if (! $line->chart_of_account_id) {
            throw new \DomainException('A prepaid line must have an Expense Account selected — this is where the amortization will post to.');
        }
        if (! $line->amortization_months || (int) $line->amortization_months < 1) {
            throw new \DomainException("Line \"{$line->description}\" is missing Amortization Months.");
        }

        $prepaidAccount = ChartOfAccount::findByCode($bill->company_id, '136');
        if (! $prepaidAccount) {
            throw new \DomainException('Prepaid Expenses account (136) is not seeded for this company — cannot post.');
        }

        $lineSubtotalFunctional = $this->lineFunctionalAmount($bill, (string) $line->line_subtotal);
        $this->addToBucket($debitBuckets, $prepaidAccount->id, $lineSubtotalFunctional);
    }

    /**
     * Deductible (default, unchanged behavior): posts to 137 VAT Input.
     * Non-deductible (confirmed with Mahmoud, August 2026): the VAT is
     * not recoverable, so it folds into the SAME account the line's
     * own subtotal already debited — see migration 000180 for the
     * full per-line-type breakdown and the documented WAC limitation.
     */
    private function addLineVat(SupplierBill $bill, SupplierBillLine $line, array &$debitBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatFunctional = $this->lineFunctionalAmount($bill, (string) $line->vat_amount);

        if ($line->is_deductible) {
            $vatAccount = ChartOfAccount::findByCode($bill->company_id, '137');
            if (! $vatAccount) {
                throw new \DomainException('VAT Input account (137) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($debitBuckets, $vatAccount->id, $vatFunctional);
            return;
        }

        if ($bill->isPrepaidBill()) {
            // Non-deductible VAT on a prepaid line folds into 136 along
            // with the line's own subtotal, and amortizes together —
            // never posted straight to the eventual expense account,
            // which would recognize part of the cost before the
            // prepayment period has actually elapsed.
            $prepaidAccount = ChartOfAccount::findByCode($bill->company_id, '136');
            if (! $prepaidAccount) {
                throw new \DomainException('Prepaid Expenses account (136) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($debitBuckets, $prepaidAccount->id, $vatFunctional);
        } elseif ($line->isGoodsLine()) {
            $this->foldNonDeductibleVatIntoGoods($bill, $line, $vatFunctional, $debitBuckets);
        } elseif ($line->isAccrualSettlement()) {
            $accruedAccount = ChartOfAccount::findByCode($bill->company_id, '228');
            if (! $accruedAccount) {
                throw new \DomainException('Accrued Expenses account (228) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($debitBuckets, $accruedAccount->id, $vatFunctional);
        } else {
            if (! $line->chart_of_account_id) {
                throw new \DomainException('An expense line must have an account selected.');
            }
            $this->addToBucket($debitBuckets, $line->chart_of_account_id, $vatFunctional);
        }
    }

    /**
     * Non-deductible VAT on a Goods/Inventory line — folds into the
     * item's own cost using the EXACT SAME split mechanism already
     * used for price variance (variance_inventory_pct between the
     * product's inventory_account_id and cogs_account_id). A Fixed
     * Asset line folds the full amount into fixed_asset_account_id
     * instead, no split — same reasoning postFixedAssetVariance()
     * already uses: one specific identifiable asset, never a COGS
     * split.
     *
     * KNOWN LIMITATION — see migration 000180: this changes the GL
     * account balance but does not recalculate the per-unit Weighted
     * Average Cost in the separate stock_movements ledger.
     */
    private function foldNonDeductibleVatIntoGoods(SupplierBill $bill, SupplierBillLine $line, string $vatFunctional, array &$debitBuckets): void
    {
        $grnLine = $line->goodsReceiptNoteLine ?? GoodsReceiptNoteLine::find($line->goods_receipt_note_line_id);
        $product = $grnLine?->product;

        if (! $product) {
            throw new \DomainException('Cannot fold non-deductible VAT — this line\'s product could not be resolved.');
        }

        if ($product->isFixedAsset()) {
            if (! $product->fixed_asset_account_id) {
                throw new \DomainException("Product {$product->code} has no Fixed Asset account assigned — cannot post non-deductible VAT.");
            }
            $this->addToBucket($debitBuckets, $product->fixed_asset_account_id, $vatFunctional);
            return;
        }

        $pct = (string) $line->variance_inventory_pct;
        $toInventory = bcdiv(bcmul($vatFunctional, $pct, 4), '100', 2);
        $toCogs = bcsub($vatFunctional, $toInventory, 2);

        if (bccomp($toInventory, '0', 2) !== 0) {
            if (! $product->inventory_account_id) {
                throw new \DomainException("Product {$product->code} has no inventory account assigned — cannot post non-deductible VAT.");
            }
            $this->addToBucket($debitBuckets, $product->inventory_account_id, $toInventory);
        }

        if (bccomp($toCogs, '0', 2) !== 0) {
            if (! $product->cogs_account_id) {
                throw new \DomainException("Product {$product->code} has no COGS account assigned — cannot post non-deductible VAT.");
            }
            $this->addToBucket($debitBuckets, $product->cogs_account_id, $toCogs);
        }
    }

    // ── Private — journal entry assembly ─────────────────────────────

    /**
     * Bill Due Date — payable-side mirror of CustomerInvoiceService::
     * calculateDueDate(). Auto-calculated at post() time from the
     * SUPPLIER's own Payment Term (Partner::payment_term_id). Uses the
     * LAST installment line's due_days (the point by which the whole
     * bill is expected to be fully paid). Falls back to the bill date
     * itself (due immediately) when the supplier has no Payment Term
     * set.
     */
    private function calculateDueDate(SupplierBill $bill): string
    {
        $term = $bill->partner?->paymentTerm;
        $lastLine = $term?->lines?->sortByDesc('due_days')->first();

        $days = $lastLine?->due_days ?? 0;

        return \Carbon\Carbon::parse($bill->bill_date)->addDays($days)->toDateString();
    }

    /**
     * "Adjust Due Date" — mirrors CustomerInvoiceService::
     * adjustDueDate() exactly. Moves due_date only, NEVER
     * original_due_date — see migration 000212's header. Every
     * adjustment is logged to audit_log with the old and new date.
     */
    public function adjustDueDate(SupplierBill $bill, string $newDueDate, User $actor): SupplierBill
    {
        if (! $bill->isPosted()) {
            throw new \DomainException('Only a posted Supplier Bill has a due date to adjust.');
        }

        $before = $bill->toArray();

        $bill->update([
            'due_date'   => $newDueDate,
            'updated_by' => $actor->id,
        ]);

        AuditLog::record($actor, 'supplier_bill.due_date_adjusted', $bill, $before, $bill->fresh()->toArray());

        return $bill->fresh();
    }

    private function postBillJournalEntry(SupplierBill $bill, array $debitBuckets, User $actor)
    {
        $apAccount = $this->resolveApAccount($bill->company_id, $bill->currency_id);

        $lines = [];

        foreach ($debitBuckets as $accountId => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $lines[] = bccomp($amount, '0', 2) > 0
                ? ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => "Supplier Bill {$bill->bill_code}"]
                : ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => bcmul($amount, '-1', 2), 'description' => "Supplier Bill {$bill->bill_code}"];
        }

        // Withholding Tax (July 2026): the supplier is paid NET of
        // whatever was withheld — that withheld portion is money the
        // company owes the tax authority, not the supplier, so it
        // splits off into its own payable (226002) rather than
        // reducing the debit side. The gross expense/inventory side
        // above is unaffected — withholding only ever changes how the
        // liability side is split, never what was actually purchased.
        $whtAmount = (string) $bill->withholding_tax_amount_functional;
        $apAmount  = bcsub((string) $bill->bill_value_functional, $whtAmount, 2);

        $lines[] = [
            'chart_of_account_id' => $apAccount->id,
            'debit'               => '0',
            'credit'              => $apAmount,
            'description'         => "Accounts Payable — {$bill->bill_code}",
        ];

        if (bccomp($whtAmount, '0', 2) > 0) {
            $whtAccount = ChartOfAccount::findByCode($bill->company_id, '226002');
            if (! $whtAccount) {
                throw new \DomainException('Withholding Tax Payable account (226002) is not seeded for this company — cannot post.');
            }

            $lines[] = [
                'chart_of_account_id' => $whtAccount->id,
                'debit'               => '0',
                'credit'              => $whtAmount,
                'description'         => "Withholding Tax withheld — {$bill->bill_code}",
            ];
        }

        return $this->journalEntryService->post(
            companyId: $bill->company_id,
            entryDate: $bill->bill_date,
            lines: $lines,
            sourceType: 'supplier_bill',
            sourceId: $bill->id,
            narration: "Supplier Bill {$bill->bill_code}" . ($bill->supplier_invoice_number ? " (Inv# {$bill->supplier_invoice_number})" : ''),
            actor: $actor
        );
    }

    // ── Private — PO rollups ──────────────────────────────────────

    private function incrementPoBilledAmount(int $poId, string $amountFunctional): void
    {
        $po = PurchaseOrder::lockForUpdate()->find($poId);
        if (! $po) {
            return;
        }

        $poValueFunctional = bcmul((string) $po->po_value, (string) $po->exchange_rate, 2);
        $newBilled = bcadd((string) $po->billed_amount, $amountFunctional, 2);

        $status = match (true) {
            bccomp($newBilled, $poValueFunctional, 2) >= 0 => 'fully_billed',
            bccomp($newBilled, '0', 2) > 0                 => 'partially_billed',
            default                                         => 'not_billed',
        };

        $po->update(['billed_amount' => $newBilled, 'billing_status' => $status]);
    }

    private function recalculatePoVarianceRollup(int $poId): void
    {
        $po = PurchaseOrder::lockForUpdate()->find($poId);
        if (! $po) {
            return;
        }

        $totalVariance = '0';
        foreach ($po->lines()->pluck('price_variance_amount') as $amount) {
            $totalVariance = bcadd($totalVariance, (string) $amount, 2);
        }

        $po->update([
            'has_price_variance'    => bccomp($totalVariance, '0', 2) !== 0,
            'price_variance_amount' => $totalVariance,
        ]);
    }

    // ── Private — shared helpers ──────────────────────────────────

    /**
     * Converts a bill-currency amount into its functional-currency
     * equivalent using this Bill's own exchange_rate — the ONE
     * formula used everywhere a functional amount is needed
     * (header totals AND journal entry buckets), so the two are
     * always exactly consistent. See class header.
     */
    private function lineFunctionalAmount(SupplierBill $bill, string $billCurrencyAmount): string
    {
        return bcmul($billCurrencyAmount, (string) $bill->exchange_rate, 2);
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    private function resolveApAccount(int $companyId, int $billCurrencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $billCurrencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        $account = ChartOfAccount::findByCode($companyId, $code);

        if (! $account) {
            throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
        }

        return $account;
    }

    private function assertNotDuplicateInvoice(SupplierBill $bill): void
    {
        if (! $bill->supplier_invoice_number) {
            return;
        }

        $exists = SupplierBill::where('company_id', $bill->company_id)
            ->where('partner_id', $bill->partner_id)
            ->where('supplier_invoice_number', $bill->supplier_invoice_number)
            ->where('id', '!=', $bill->id)
            ->where('status', '!=', SupplierBill::STATUS_CANCELLED)
            ->exists();

        if ($exists) {
            throw new DuplicateSupplierInvoiceException(
                "Supplier invoice #{$bill->supplier_invoice_number} for this supplier has already been billed on another Supplier Bill."
            );
        }
    }

    /**
     * Every Goods Receipt line billed must have been received FROM this
     * bill's supplier (and this company). The form could keep a hidden line
     * of the previous supplier after the supplier was changed; without this
     * check the bill would bill one supplier for another supplier's goods.
     */
    private function assertGrnLinesBelongToSupplier(int $companyId, int $partnerId, array $lines): void
    {
        $ids = collect($lines)->pluck('goods_receipt_note_line_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $ok = \App\Models\GoodsReceiptNoteLine::withoutGlobalScopes()
            ->whereIn('goods_receipt_note_lines.id', $ids)
            ->join('goods_receipt_notes', 'goods_receipt_notes.id', '=', 'goods_receipt_note_lines.goods_receipt_note_id')
            ->where('goods_receipt_notes.company_id', $companyId)
            ->where('goods_receipt_notes.partner_id', $partnerId)
            ->count();

        if ($ok !== $ids->count()) {
            throw new \DomainException('One or more billed Goods Receipt lines were not received from the selected supplier. Remove them or pick the right supplier.');
        }
    }

    private function syncLines(SupplierBill $bill, int $companyId, array $lines): void
    {
        $bill->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal = bcmul($quantity, $unitPrice, 2);
            $vatRate = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            // Withholding Tax (July 2026, moved to line level): same
            // "% of this line's own subtotal, before VAT" calculation
            // as VAT above — confirmed a goods line and a service line
            // on the same bill can carry different rates.
            $whtRate = (string) ($line['withholding_tax_rate'] ?? '0');
            $whtAmount = bcdiv(bcmul($subtotal, $whtRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            SupplierBillLine::create([
                'company_id'                 => $companyId,
                'supplier_bill_id'           => $bill->id,
                'goods_receipt_note_line_id' => $line['goods_receipt_note_line_id'] ?? null,
                'chart_of_account_id'        => $line['chart_of_account_id'] ?? null,
                'accrual_id'                 => $line['accrual_id'] ?? null,
                'product_id'                 => $line['product_id'] ?? null,
                'description'                => $line['description'],
                'quantity'                   => $quantity,
                'uom_id'                     => $line['uom_id'] ?? null,
                'unit_price'                 => $unitPrice,
                'line_subtotal'              => $subtotal,
                'vat_rate'                   => $vatRate,
                'vat_amount'                 => $vatAmount,
                'is_deductible'              => array_key_exists('is_deductible', $line) ? (bool) $line['is_deductible'] : true,
                'withholding_tax_rate'       => $whtRate,
                'withholding_tax_amount'     => $whtAmount,
                'line_total'                 => $lineTotal,
                'variance_amount'            => 0,
                'variance_inventory_pct'     => $line['variance_inventory_pct'] ?? 100,
                'cost_center_id'             => $line['cost_center_id'] ?? null,
                'project_id'                 => $line['project_id'] ?? null,
                'sales_order_id'             => $line['sales_order_id'] ?? null,
                'sort_order'                 => $i + 1,
                'amortization_months'        => $line['amortization_months'] ?? null, // Prepaid Expense Bill only — see migration 000192
            ]);
        }
    }

    private function recalculateTotals(SupplierBill $bill): void
    {
        $lines = $bill->lines()->get();

        $subtotal = '0';
        $vat = '0';
        $wht = '0';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $vat = bcadd($vat, (string) $line->vat_amount, 2);
            $wht = bcadd($wht, (string) $line->withholding_tax_amount, 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $bill->update([
            'subtotal_amount'            => $subtotal,
            'vat_amount'                 => $vat,
            'bill_value'                 => $total,
            'subtotal_amount_functional' => bcmul($subtotal, (string) $bill->exchange_rate, 2),
            'vat_amount_functional'      => bcmul($vat, (string) $bill->exchange_rate, 2),
            'bill_value_functional'      => bcmul($total, (string) $bill->exchange_rate, 2),
            // Withholding Tax (July 2026, moved to line level) — this
            // is now the SUM of every line's own withheld amount, the
            // same relationship subtotal_amount already has to
            // line_subtotal — not computed from a single bill-level
            // rate anymore.
            'withholding_tax_amount'             => $wht,
            'withholding_tax_amount_functional'  => bcmul($wht, (string) $bill->exchange_rate, 2),
        ]);
    }

    private function generateBillCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\SupplierBill::class, 'bill_code', 'SB', $date);
    }
}