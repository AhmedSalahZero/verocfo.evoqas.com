<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerInvoiceService
//
//  All business logic for Customer Invoices — Sales Cycle, module 1.
//  Turns a Sales Order (commercial-only, Rule 28) into a real
//  financial transaction. See migrations 000118-000119 for the
//  original design, and
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md for the
//  physical-goods branching added in this revision.
//
//  Posting now branches PER LINE by Product::has_stock:
//
//    SERVICE LINE (has_stock = false) — unchanged from the original
//    design: Cr revenue_account_id directly, full amount, at invoice
//    time. No delivery involvement at all.
//
//    PHYSICAL-GOODS LINE (has_stock = true) — reads the SO line's net
//    delivery position (delivered_quantity - invoiced_quantity,
//    BEFORE this invoice) to split the line's quantity:
//      - the portion already delivered clears existing Accrued
//        Revenue (143): Cr 143, plus any price variance vs. the SO
//        price flows to Revenue (design doc Section 4).
//      - the portion NOT yet delivered (advance billing) creates
//        fresh Deferred Revenue (236): Cr 236, at the invoice's own
//        price — no variance concept, since there's no prior accrual
//        to compare against.
//    Both portions still contribute to the same AR debit and the same
//    VAT credit as before — VAT recognizes at invoice regardless of
//    this split (design doc Section 3).
//
//  RULE 28.2 / 28.3 — enforced here, not just at the Form Request
//  level: post() re-validates the SO is still 'active' and that this
//  invoice plus every other posted invoice against the same SO does
//  not exceed so_value, using the already-existing
//  SOValueExceededException.
//
//  CURRENCY / FUNCTIONAL CONSISTENCY: every GL-posted figure is
//  bcmul(invoice-currency amount, exchange_rate, 2), computed
//  identically in syncLines()/recalculateTotals() and in post().
//
//  AUDIT C8 FIX (Sept 2026) — goods invoiced on a foreign-currency
//  Sales Order. Accrued Revenue (143) is now cleared at the EGP value
//  the Delivery Note actually booked for those units (the SO line's
//  running accrued_revenue_functional, pro rata), and the gap to the
//  invoice-rate value goes to 431 Realized FX Gain / 571 Realized FX
//  Loss. Deferred Revenue (236) created here is added to the SO line's
//  deferred_revenue_functional so the delivery clears exactly that.
//  EGP orders: identical figures, no FX line.
//
//  AUDIT H18 (Sept 2026): a SERVICE line with a service period running
//  beyond the invoice month (e.g. a year's maintenance invoiced up
//  front) is credited to 236 Deferred Revenue and spread month by
//  month — see RevenueScheduleService. Set-to-Draft reverses any
//  month already released.
//
//  @layer    Service
//  @depends  CustomerInvoice, CustomerInvoiceLine, SalesOrder,
//            SalesOrderLine, Product, ChartOfAccount,
//            CompanyActiveCurrency, JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Exceptions\Accounting\SOValueExceededException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\CustomerPaymentAllocation;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerInvoiceService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private CustomerPaymentService $customerPaymentService,
        private FiscalYearService $fiscalYearService,
    ) {
    }

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CustomerInvoice::with(['partner:id,name_en,name_ar', 'salesOrder:id,so_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('invoice_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($filters['sales_order_id'] ?? null, fn ($q, $so) => $q->where('sales_order_id', $so))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) ────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): CustomerInvoice
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $this->guardSalesOrder($companyId, $data, $lines);

            $data['company_id']    = $companyId;
            $data['created_by']    = $actor->id;
            $data['invoice_code']  = $this->generateInvoiceCode($companyId, $data['invoice_date'] ?? null);
            $data['status']        = CustomerInvoice::STATUS_DRAFT;
            $data['exchange_rate'] = app(FxService::class)->resolveRate(
                $companyId,
                ($data['currency_id'] ?? null) ? (int) ($data['currency_id'] ?? null) : null,
                substr((string) ($data['invoice_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1

            $invoice = CustomerInvoice::create($data);

            $this->syncLines($invoice, $companyId, $lines);
            $this->recalculateTotals($invoice);

            AuditLog::record($actor, 'customer_invoice.created', $invoice, null, $invoice->fresh('lines')->toArray());

            return $invoice->fresh(['partner', 'salesOrder', 'currency', 'lines']);
        });
    }

    // ── Update (draft only) ───────────────────────────────────────

    public function update(int $companyId, CustomerInvoice $invoice, array $data, User $actor): CustomerInvoice
    {
        if (! $invoice->isDraft()) {
            throw new \DomainException('Only a draft Customer Invoice can be edited.');
        }

        return DB::transaction(function () use ($companyId, $invoice, $data, $actor) {
            $before = $invoice->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines']);

            $this->guardSalesOrder($companyId, $data, $lines);

            $data['updated_by'] = $actor->id;
            // Audit C8: a foreign invoice saved without a rate takes the
            // rate table's, never a silent 1.
            if (array_key_exists('exchange_rate', $data) || array_key_exists('currency_id', $data)) {
                $currencyId = (int) ($data['currency_id'] ?? $invoice->currency_id);
                $data['exchange_rate'] = app(FxService::class)->resolveRate(
                    $companyId, $currencyId ?: null,
                    substr((string) ($data['invoice_date'] ?? $invoice->invoice_date), 0, 10),
                    $data['exchange_rate'] ?? null
                );
            }
            $invoice->update($data);

            $this->syncLines($invoice, $companyId, $lines);
            $this->recalculateTotals($invoice);

            AuditLog::record($actor, 'customer_invoice.updated', $invoice, $before, $invoice->fresh('lines')->toArray());

            return $invoice->fresh(['partner', 'salesOrder', 'currency', 'lines']);
        });
    }

    // ── Delete (draft only) ───────────────────────────────────────

    public function delete(CustomerInvoice $invoice, User $actor): void
    {
        if (! $invoice->isDraft()) {
            throw new \DomainException('Only a draft Customer Invoice can be deleted — a posted invoice requires a Credit Note.');
        }

        $before = $invoice->toArray();
        $invoice->lines()->delete();
        $invoice->delete();
        AuditLog::record($actor, 'customer_invoice.deleted', $invoice, $before, null);
    }

    // ── Post ──────────────────────────────────────────────────────

    /**
     * @param bool $overrideSoLimit  Confirmed with Mahmoud (August 2026):
     *   exceeding the SO value is no longer always a hard block. If the
     *   agreed price genuinely went up, the caller can pass true here
     *   (after the user has already been warned and chosen to proceed)
     *   and the invoice posts anyway, flagged via exceeds_so_value —
     *   which drives the "Exceeds SO" badge on the index. so_value on
     *   the Sales Order itself is deliberately NEVER touched by this —
     *   the badge is the record of the overage, not a rewrite of the
     *   original agreement.
     */
    public function post(CustomerInvoice $invoice, User $actor, bool $overrideSoLimit = false): CustomerInvoice
    {
        if (! $invoice->isDraft()) {
            throw new \DomainException('Only a draft Customer Invoice can be posted.');
        }

        $invoice->loadMissing('lines.product', 'lines.salesOrderLine', 'salesOrder', 'partner.collectionTerm.lines');

        if ($invoice->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Customer Invoice with no lines.');
        }

        // Rule 26.4 — revenue invoices are hard-blocked from posting
        // back-dated once a newer fiscal year already exists for this
        // company. Unlike supplier bills/payments/journals (which may
        // legally back-date into an open prior period), revenue
        // recognition cannot be reopened once a later FY has started.
        if ($this->fiscalYearService->newerFiscalYearExists($invoice->company_id, (string) $invoice->invoice_date)) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Customer Invoice [{$invoice->invoice_code}] — its invoice date ({$invoice->invoice_date->toDateString()}) falls in a fiscal year that has since been superseded by a newer one. Revenue invoices cannot be back-dated once a newer fiscal year exists (Rule 26.4)."
            );
        }

        return DB::transaction(function () use ($invoice, $actor, $overrideSoLimit) {
            $before = $invoice->toArray();

            $so = null;
            $exceedsSoValue = false;
            $newInvoicedSubtotal = null;

            if ($invoice->sales_order_id) {
            $so = SalesOrder::lockForUpdate()->findOrFail($invoice->sales_order_id);

            // Rule 28.3 — SO status must be active to receive invoices
            if ($so->status !== 'active') {
                throw new \DomainException("Sales Order [{$so->so_code}] is not active — invoices can only post against an active Sales Order.");
            }

            // Rule 28.2 — invoice total cannot exceed remaining SO value.
            //
            // BUG FIX (reported by Mahmoud, August 2026): so_value is a
            // purely commercial figure — sales_order_lines has no VAT
            // column at all, VAT only ever enters at invoice time. This
            // must compare against subtotal_amount_functional (excl.
            // VAT), never invoice_value_functional (incl. VAT) — the
            // old code compared VAT-inclusive against VAT-exclusive and
            // overstated every invoice that carried any VAT.
            // AUDIT M-11: both sides are now in the Sales Order's own
            // currency. The old code converted so_value at the ORDER's
            // rate but added the invoice at the INVOICE's rate, so a USD
            // order was refused (or over-billed) purely because the rate
            // moved between the two dates.
            $invoiceSubtotalInSo = $so->inOrderCurrency(
                (string) $invoice->subtotal_amount,
                (string) $invoice->subtotal_amount_functional,
                $invoice->currency_id
            );
            $soValue             = bcadd((string) $so->so_value, '0', 2);
            $newInvoicedSubtotal = bcadd((string) $so->invoiced_amount, $invoiceSubtotalInSo, 2);
            $exceedsSoValue      = bccomp($newInvoicedSubtotal, $soValue, 2) > 0;

            if ($exceedsSoValue && ! $overrideSoLimit) {
                $remaining = bcsub($soValue, (string) $so->invoiced_amount, 2);
                $excess    = bcsub($invoiceSubtotalInSo, $remaining, 2);
                throw new SOValueExceededException(
                    "Invoice subtotal ({$invoiceSubtotalInSo}) exceeds remaining Sales Order value ({$remaining}) by {$excess}."
                );
            }
            } else {
                $this->assertServiceInvoice($invoice);
            }

            $creditBuckets = []; // chart_of_account_id => functional amount (revenue/VAT/143/236)
            $extraDebitBuckets = []; // chart_of_account_id => functional amount (negative price variance only)
            $schedulesToCreate = []; // Audit H18: [line, functional amount]

            foreach ($invoice->lines as $line) {
                if ($line->product && $line->product->has_stock) {
                    $this->postPhysicalGoodsLine($invoice, $line, $creditBuckets, $extraDebitBuckets);
                } elseif (RevenueScheduleService::needsSchedule($invoice, $line)) {
                    // Audit H18: a service invoiced for a period beyond
                    // this month (e.g. a year's maintenance up front) —
                    // credited to 236 Deferred Revenue, released month by
                    // month over the service period.
                    $functional = bcmul((string) $line->line_subtotal, (string) $invoice->exchange_rate, 2);
                    $this->addToBucket($creditBuckets, $this->revenueSchedules()->deferredRevenueAccountId($invoice->company_id), $functional);
                    $schedulesToCreate[] = [$line, $functional];
                } else {
                    // Service line — unchanged, full amount at invoice time.
                    $this->addToBucket($creditBuckets, $line->revenue_account_id, bcmul((string) $line->line_subtotal, (string) $invoice->exchange_rate, 2));
                }

                $this->addLineVat($invoice, $line, $creditBuckets);
            }

            $this->alignReceivableToLedgerLines($invoice, $creditBuckets, $extraDebitBuckets);

            $journalEntry = $this->postInvoiceJournalEntry($invoice, $creditBuckets, $extraDebitBuckets, $actor);

            foreach ($schedulesToCreate as [$line, $functional]) {
                $this->revenueSchedules()->createSchedule($invoice, $line, $functional, $actor);
            }

            $dueDate = $this->calculateDueDate($invoice);

            $invoice->update([
                'status'              => CustomerInvoice::STATUS_POSTED,
                'exceeds_so_value'    => $exceedsSoValue,
                'journal_entry_id'    => $journalEntry->id,
                'amount_outstanding'  => $invoice->invoice_value_functional,
                'amount_paid'         => 0,
                'payment_term_id'     => $invoice->partner->collection_term_id,
                'original_due_date'   => $dueDate,
                'due_date'            => $dueDate,
                'posted_by'           => $actor->id,
                'posted_at'           => now(),
            ]);

            if ($so) {
                $so->update(['invoiced_amount' => $newInvoicedSubtotal]);
            }

            AuditLog::record($actor, 'customer_invoice.posted', $invoice, $before, $invoice->fresh()->toArray());

            return $invoice->fresh(['partner', 'salesOrder', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    // ── Reopen to Draft ───────────────────────────────────────────

    /**
     * Reopens a posted invoice back to draft — the payable-side
     * reversal proven on Customer Collection/Supplier Payment, applied
     * here for the first time. Reverses ONLY the accounting side:
     *   - the invoice's own journal entry (AR / Revenue / VAT /
     *     Accrued Revenue / Deferred Revenue — all one entry, one
     *     reversal)
     *   - the Sales Order's invoiced_amount and each line's own
     *     invoiced_quantity counter — bookkeeping only, NOT physical
     *     stock. delivered_quantity (owned by Delivery Note) is never
     *     touched — a delivery that already happened stays exactly as
     *     it was; only "how much has been billed so far" unwinds.
     *   - each line's own accrued_cleared_quantity /
     *     deferred_created_quantity / price_variance_amount tracking,
     *     reset to 0 so re-posting recalculates fresh against
     *     whatever the CURRENT delivered/invoiced position is by then.
     *
     * Confirmed with Mahmoud — the invoice/bill ↔ payment/collection
     * linking rule: a payment allocation matched against this invoice
     * is either
     *   (a) a SEPARATE later "Apply" action (its own distinct journal
     *       entry) — automatically unlinked here, one at a time,
     *       without touching the payment itself at all, or
     *   (b) part of that payment's ORIGINAL settlement posting
     *       (shares the payment's own journal entry) — cannot be
     *       silently unwound from the invoice's side, since doing so
     *       would mean reversing the WHOLE payment, potentially
     *       un-settling other unrelated invoices it also paid off in
     *       that same original posting. Blocked with a clear pointer
     *       to which payment needs reopening first.
     */
    public function setToDraft(CustomerInvoice $invoice, User $actor): CustomerInvoice
    {
        // Audit C7: an opening-balance invoice has no lines, no Sales
        // Order and no journal entry of its own — it can never become
        // a draft. Correct it with a Credit / Debit Note instead.
        if ($invoice->is_opening_balance) {
            throw new \DomainException('This is an opening-balance invoice — it cannot be set back to draft. Use a Credit Note or Debit Note to correct it.');
        }
        if (! $invoice->isPosted()) {
            throw new \DomainException('Only a posted Customer Invoice can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($invoice->company_id, (string) $invoice->invoice_date)) {
            throw new \DomainException('This invoice\'s accounting period is closed — it cannot be reopened.');
        }

        // Audit H16 (Sept 2026) — a posted Credit Note, or a Sales Return
        // that dealt with goods already billed on this invoice's SO lines,
        // relies on the invoice as it stands. Reopening it would leave
        // those figures stranded (AR credited twice, or revenue parked in
        // 236 with no billing behind it).
        if ($invoice->creditNotes()->where('status', \App\Models\CreditNote::STATUS_POSTED)->exists()) {
            throw new \DomainException('A posted Credit Note exists against this invoice — it cannot be reopened. Correct it with a Credit Note or Customer Debit Note instead.');
        }
        $soLineIds = $invoice->lines()->whereNotNull('sales_order_line_id')->pluck('sales_order_line_id');
        if ($soLineIds->isNotEmpty() && \App\Models\SalesReturnLine::whereIn('sales_order_line_id', $soLineIds)
                ->where('invoiced_quantity', '>', 0)
                ->whereHas('salesReturn', fn ($q) => $q->where('status', \App\Models\SalesReturn::STATUS_POSTED))
                ->exists()) {
            throw new \DomainException('A Sales Return has been posted for goods billed on this invoice — it cannot be reopened. Cancel the return first (if it raised no credit note), or correct the invoice with a Credit / Debit Note.');
        }

        // Audit M9 — a customer credit balance applied to this invoice relies on it.
        if (\App\Models\CustomerCreditApplication::withoutGlobalScopes()
            ->where('customer_invoice_id', $invoice->id)->where('status', \App\Models\CustomerCreditApplication::STATUS_APPLIED)->exists()) {
            throw new \DomainException('A customer credit balance has been applied to this invoice — undo that application first (Accounting → Customer Credits), then reopen it.');
        }

        // Block BEFORE touching anything if any linked allocation is
        // part of a payment's original posting AND that payment ALSO
        // settled some other invoice — fail loud and clear, not
        // halfway through an otherwise-irreversible transaction.
        //
        // A collection whose original entry only ever touched THIS one
        // invoice is safe to unlink automatically, right from here —
        // releaseOriginalAllocation() reverses just that entry and
        // re-posts the same money as unapplied, leaving the collection
        // itself (and its cheque, if any) untouched. A collection whose
        // original entry combined SEVERAL invoices into one shared AR
        // credit still needs the manual "reopen the collection" step,
        // since unwinding it here would silently un-settle those other,
        // unrelated invoices too.
        $invoice->loadMissing('allocations.customerPayment');
        foreach ($invoice->allocations as $allocation) {
            $payment = $allocation->customerPayment;
            $isOriginalPosting = $allocation->journal_entry_id && $payment && $allocation->journal_entry_id === $payment->journal_entry_id;

            if ($isOriginalPosting) {
                $touchesOtherInvoices = CustomerPaymentAllocation::where('customer_payment_id', $payment->id)
                    ->where('customer_invoice_id', '!=', $invoice->id)
                    ->where('journal_entry_id', $payment->journal_entry_id)
                    ->exists();

                if ($touchesOtherInvoices) {
                    throw new \DomainException(
                        "This invoice was settled as part of Collection {$payment->payment_code}'s original posting, which also settled other invoices — reopen that collection first (Set to Draft), then re-save it without this invoice, before this invoice can be reopened."
                    );
                }
            }
        }

        $invoice->loadMissing('lines');

        return DB::transaction(function () use ($invoice, $actor) {
            $before = $invoice->toArray();

            foreach ($invoice->allocations as $allocation) {
                $payment = $allocation->customerPayment;
                $isOriginalPosting = $allocation->journal_entry_id && $payment && $allocation->journal_entry_id === $payment->journal_entry_id;

                if ($isOriginalPosting) {
                    // Fix (August 2026, confirmed with Mahmoud — this
                    // replaces the earlier approach): already confirmed
                    // above this payment touches only THIS invoice, but
                    // that no longer means "reverse the whole collection."
                    // releaseOriginalAllocation() unlinks just this
                    // invoice and re-posts the money as unapplied — the
                    // collection stays posted and its cheque (bank,
                    // number, due date, safe, status) is never touched.
                    $this->customerPaymentService->releaseOriginalAllocation($allocation, $actor);
                } else {
                    // A SEPARATE "Apply" allocation — safe to unlink on
                    // its own, never touches the payment itself.
                    $this->customerPaymentService->unallocate($allocation, $actor);
                }
            }

            // Audit H18: undo any monthly revenue already released from 236.
            $this->revenueSchedules()->cancelForInvoice($invoice, $actor);

            if ($invoice->journal_entry_id) {
                $entry = JournalEntry::findOrFail($invoice->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$invoice->invoice_code);
            }

            $so = SalesOrder::lockForUpdate()->find($invoice->sales_order_id);
            if ($so) {
                // BUG FIX (same root cause as post()): must reverse the
                // VAT-exclusive subtotal that was actually added to
                // invoiced_amount, not the VAT-inclusive invoice total.
                // AUDIT M-11: in the order's own currency, as post() adds it.
                $so->update([
                    'invoiced_amount' => bcsub((string) $so->invoiced_amount, $so->inOrderCurrency(
                        (string) $invoice->subtotal_amount, (string) $invoice->subtotal_amount_functional, $invoice->currency_id
                    ), 2),
                ]);
            }

            foreach ($invoice->lines as $line) {
                if ($line->sales_order_line_id) {
                    $soLine = SalesOrderLine::lockForUpdate()->find($line->sales_order_line_id);
                    if ($soLine) {
                        $soLine->update([
                            'invoiced_quantity' => bcsub((string) $soLine->invoiced_quantity, (string) $line->quantity, 4),
                            // Audit C8: put back exactly the EGP amounts this line moved.
                            'accrued_revenue_functional'  => bcadd((string) $soLine->accrued_revenue_functional, (string) $line->accrued_cleared_functional, 2),
                            'deferred_revenue_functional' => bcsub((string) $soLine->deferred_revenue_functional, (string) $line->deferred_created_functional, 2),
                        ]);
                    }
                }

                $line->update([
                    'accrued_cleared_quantity'   => 0,
                    'deferred_created_quantity'  => 0,
                    'price_variance_amount'      => 0,
                    'accrued_cleared_functional'  => 0,
                    'deferred_created_functional' => 0,
                    'fx_difference_functional'    => 0,
                ]);
            }

            $invoice->update([
                'status'             => CustomerInvoice::STATUS_DRAFT,
                'journal_entry_id'   => null,
                'exceeds_so_value'   => false, // re-evaluated fresh next time this invoice actually posts
                'posted_by'          => null,
                'posted_at'          => null,
                'amount_paid'        => 0,
                'amount_wht_deducted'=> 0,
                'amount_outstanding' => 0,
                'original_due_date'  => null, // re-computed fresh next time this invoice actually posts
                'due_date'           => null,
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'customer_invoice.set_to_draft', $invoice, $before, $invoice->fresh()->toArray());

            return $invoice->fresh(['partner', 'salesOrder', 'currency', 'lines']);
        });
    }

    // ── Cancel (draft only) ──────────────────────────────────────

    public function cancel(CustomerInvoice $invoice, User $actor): CustomerInvoice
    {
        if (! $invoice->isDraft()) {
            throw new \DomainException('Only a draft Customer Invoice can be cancelled — a posted invoice requires a Credit Note.');
        }

        return DB::transaction(function () use ($invoice, $actor) {
            $before = $invoice->toArray();
            $invoice->update(['status' => CustomerInvoice::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'customer_invoice.cancelled', $invoice, $before, $invoice->fresh()->toArray());
            return $invoice->fresh();
        });
    }

    // ── Private — physical-goods branch (Delivery & Revenue Recognition) ──

    /**
     * design doc Sections 8.3/9: reads the SO line's net delivery
     * position BEFORE this invoice to split this line's quantity
     * between clearing existing Accrued Revenue (143) and creating
     * fresh Deferred Revenue (236). Updates the SO line's own
     * invoiced_quantity and the CustomerInvoiceLine's split columns.
     */
    private function postPhysicalGoodsLine(CustomerInvoice $invoice, CustomerInvoiceLine $line, array &$creditBuckets, array &$extraDebitBuckets): void
    {
        $soLine = SalesOrderLine::lockForUpdate()->findOrFail($line->sales_order_line_id);

        $netBefore = bcsub((string) $soLine->delivered_quantity, (string) $soLine->invoiced_quantity, 4);
        $lineQty   = (string) $line->quantity;

        if (bccomp($netBefore, '0', 4) <= 0) {
            $accruedClearedQty  = '0';
            $deferredCreatedQty = $lineQty;
        } else {
            $accruedClearedQty  = bccomp($lineQty, $netBefore, 4) <= 0 ? $lineQty : $netBefore;
            $deferredCreatedQty = bcsub($lineQty, $accruedClearedQty, 4);
        }

        $priceVariance = '0';

        $accruedBooked = '0.00';
        $fxDifference  = '0.00';
        $deferredAmountFunctional = '0.00';

        if (bccomp($accruedClearedQty, '0', 4) > 0) {
            $accruedClearAmountFunctional = bcmul(bcmul($accruedClearedQty, (string) $soLine->unit_price, 2), (string) $invoice->exchange_rate, 2);
            $invoicedAmountFunctional     = bcmul(bcmul($accruedClearedQty, (string) $line->unit_price, 2), (string) $invoice->exchange_rate, 2);
            $priceVariance = bcsub($invoicedAmountFunctional, $accruedClearAmountFunctional, 2);

            // Audit C8: 143 is cleared at what the delivery actually
            // BOOKED for these units (pro rata of the SO line's EGP
            // balance) — not re-valued at the invoice rate. The gap
            // between that and the same units at the invoice rate is
            // realised FX (431 gain / 571 loss). For an EGP order both
            // figures are equal and nothing changes.
            $accruedBooked = $this->proRata((string) $soLine->accrued_revenue_functional, $accruedClearedQty, $netBefore);
            $fxDifference  = bcsub($accruedClearAmountFunctional, $accruedBooked, 2);

            $accruedAccount = $this->resolveAccruedRevenueAccount($invoice->company_id);
            $this->addToBucket($creditBuckets, $accruedAccount->id, $accruedBooked);

            if (bccomp($fxDifference, '0', 2) > 0) {
                $this->addToBucket($creditBuckets, $this->resolveFxAccount($invoice->company_id, '431')->id, $fxDifference);
            } elseif (bccomp($fxDifference, '0', 2) < 0) {
                $this->addToBucket($extraDebitBuckets, $this->resolveFxAccount($invoice->company_id, '571')->id, bcmul($fxDifference, '-1', 2));
            }

            if (bccomp($priceVariance, '0', 2) > 0) {
                $this->addToBucket($creditBuckets, $soLine->revenue_account_id, $priceVariance);
            } elseif (bccomp($priceVariance, '0', 2) < 0) {
                $this->addToBucket($extraDebitBuckets, $soLine->revenue_account_id, bcmul($priceVariance, '-1', 2));
            }
        }

        if (bccomp($deferredCreatedQty, '0', 4) > 0) {
            $deferredAmountFunctional = bcmul(bcmul($deferredCreatedQty, (string) $line->unit_price, 2), (string) $invoice->exchange_rate, 2);
            $deferredAccount = $this->resolveDeferredRevenueAccount($invoice->company_id);
            $this->addToBucket($creditBuckets, $deferredAccount->id, $deferredAmountFunctional);
        }

        $line->update([
            'accrued_cleared_quantity'   => $accruedClearedQty,
            'deferred_created_quantity'  => $deferredCreatedQty,
            'price_variance_amount'      => $priceVariance,
            'accrued_cleared_functional'  => $accruedBooked,
            'deferred_created_functional' => $deferredAmountFunctional,
            'fx_difference_functional'    => $fxDifference,
        ]);

        $soLine->update([
            'invoiced_quantity'           => bcadd((string) $soLine->invoiced_quantity, $lineQty, 4),
            'accrued_revenue_functional'  => bcsub((string) $soLine->accrued_revenue_functional, $accruedBooked, 2),
            'deferred_revenue_functional' => bcadd((string) $soLine->deferred_revenue_functional, $deferredAmountFunctional, 2),
        ]);
    }

    /** Audit C8: share of an EGP balance for $part of $whole units; the last unit takes the remainder. */
    private function proRata(string $balance, string $part, string $whole): string
    {
        if (bccomp($whole, '0', 4) <= 0 || bccomp($part, $whole, 4) >= 0) {
            return bcadd($balance, '0', 2);
        }

        $share = bcdiv(bcmul($balance, $part, 10), $whole, 10);
        $offset = str_starts_with($share, '-') ? '-0.005' : '0.005';

        return bcadd($share, $offset, 2);
    }

    /** 431 Realized FX Gain / 571 Realized FX Loss (Audit C8). */
    private function resolveFxAccount(int $companyId, string $code): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} (Realized FX ".($code === '431' ? 'Gain' : 'Loss').') — check the seeder.');
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

    // ── Private — journal entry assembly ─────────────────────────

    private function addLineVat(CustomerInvoice $invoice, CustomerInvoiceLine $line, array &$creditBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatAccount = ChartOfAccount::findByCode($invoice->company_id, '226001');
        if (! $vatAccount) {
            throw new \DomainException('VAT Output account (226001) is not seeded for this company — cannot post.');
        }

        $vatFunctional = bcmul((string) $line->vat_amount, (string) $invoice->exchange_rate, 2);
        $this->addToBucket($creditBuckets, $vatAccount->id, $vatFunctional);
    }

    /**
     * Audit M10 — the receivable debited must equal the sum of the
     * revenue / VAT / clearing lines credited, to the cent. Invoices
     * saved before the per-line rounding fix (or lines whose accrual
     * split rounds separately) can differ by a few cents from the
     * stored total, which used to be refused as "does not balance".
     * When the gap is pure rounding (at most 0.01 per line) the
     * invoice total is aligned to the ledger lines; a larger gap is a
     * real error and is left to be reported as before.
     */
    private function alignReceivableToLedgerLines(CustomerInvoice $invoice, array $creditBuckets, array $extraDebitBuckets): void
    {
        $credits = '0';
        foreach ($creditBuckets as $amount) {
            $credits = bcadd($credits, (string) $amount, 2);
        }
        foreach ($extraDebitBuckets as $amount) {
            $credits = bcsub($credits, (string) $amount, 2);
        }

        $gap = bcsub($credits, (string) $invoice->invoice_value_functional, 2);
        if (bccomp($gap, '0', 2) === 0) {
            return;
        }

        $tolerance = bcmul('0.01', (string) max(1, $invoice->lines->count()), 2);
        if (bccomp(ltrim($gap, '-'), $tolerance, 2) <= 0) {
            $invoice->update(['invoice_value_functional' => $credits]);
        }
    }

    private function postInvoiceJournalEntry(CustomerInvoice $invoice, array $creditBuckets, array $extraDebitBuckets, User $actor)
    {
        $arAccount = $this->resolveArAccount($invoice->company_id, $invoice->currency_id);

        $lines = [
            ['chart_of_account_id' => $arAccount->id, 'debit' => (string) $invoice->invoice_value_functional, 'credit' => '0', 'description' => 'Customer Invoice '.$invoice->invoice_code],
        ];

        foreach ($extraDebitBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Price variance — Customer Invoice '.$invoice->invoice_code];
        }

        foreach ($creditBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Customer Invoice '.$invoice->invoice_code];
        }

        return $this->journalEntryService->post(
            companyId: $invoice->company_id,
            entryDate: $invoice->invoice_date,
            lines: $lines,
            sourceType: 'customer_invoice',
            sourceId: $invoice->id,
            narration: "Customer Invoice {$invoice->invoice_code} — {$invoice->partner->name_en}"
                .($invoice->salesOrder?->so_code ? " (SO {$invoice->salesOrder->so_code})" : ' (service)'),
            actor: $actor,
        );
    }

    /**
     * Invoice Due Date — auto-calculated at post() time from the
     * CUSTOMER's own Collection Term (Partner::collection_term_id),
     * per Mahmoud (September 2026). Uses the LAST installment line's
     * due_days (the point by which the whole invoice is expected to
     * be fully settled), not the first — a 50%-at-30-days /
     * 50%-at-60-days term means the invoice is genuinely "due" at 60
     * days, not 30. Falls back to the invoice date itself (due
     * immediately) when the customer has no Collection Term set —
     * every invoice gets a usable due date either way, never null on
     * a posted invoice.
     */
    private function calculateDueDate(CustomerInvoice $invoice): string
    {
        $term = $invoice->partner?->collectionTerm;
        $lastLine = $term?->lines?->sortByDesc('due_days')->first();

        $days = $lastLine?->due_days ?? 0;

        return \Carbon\Carbon::parse($invoice->invoice_date)->addDays($days)->toDateString();
    }

    /**
     * "Adjust Due Date" — moves due_date only, NEVER original_due_date
     * (see migration 000209's header — that field is a permanent
     * record of what was originally promised). Every adjustment is
     * logged to audit_log with the old and new date, which is all a
     * future "collection pattern per customer" report needs — no
     * separate history table required.
     */
    public function adjustDueDate(CustomerInvoice $invoice, string $newDueDate, User $actor): CustomerInvoice
    {
        if (! $invoice->isPosted()) {
            throw new \DomainException('Only a posted Customer Invoice has a due date to adjust.');
        }

        $before = $invoice->toArray();

        $invoice->update([
            'due_date'   => $newDueDate,
            'updated_by' => $actor->id,
        ]);

        AuditLog::record($actor, 'customer_invoice.due_date_adjusted', $invoice, $before, $invoice->fresh()->toArray());

        return $invoice->fresh();
    }

    private function resolveArAccount(int $companyId, int $invoiceCurrencyId): ChartOfAccount
    {        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $invoiceCurrencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

        $account = ChartOfAccount::findByCode($companyId, $code);

        if (! $account) {
            throw new \DomainException("Accounts Receivable account ({$code}) is not seeded for this company — cannot post.");
        }

        return $account;
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    // ── Private — lines / totals ─────────────────────────────────

    /** Audit H18 — resolved lazily so the constructor wiring is unchanged. */
    private function revenueSchedules(): RevenueScheduleService
    {
        return app(RevenueScheduleService::class);
    }

    /**
     * The Sales Order must belong to this company AND to the invoice's own
     * customer, and every invoice line must come from that same order.
     * The form lets a user switch customer while a slow list is still
     * loading, and a hand-made request could name any ids — without this
     * check an invoice could post receivables to the wrong customer.
     */
    /** An invoice with a sales order keeps the order checks. Without one, only service lines are allowed. */
    private function guardSalesOrder(int $companyId, array &$data, array $lines): void
    {
        if (! empty($data['sales_order_id'])) {
            $this->assertOrderMatchesInvoice($companyId, $data, $lines);

            return;
        }

        $data['sales_order_id'] = null;
        $this->assertServiceLines($lines);
    }

    private function assertServiceLines(array $lines): void
    {
        foreach ($lines as $line) {
            if (! empty($line['sales_order_line_id'])) {
                throw new \DomainException('A service invoice has no sales order, so a line cannot point at a sales order line.');
            }

            if (! empty($line['product_id'])) {
                $product = Product::withoutGlobalScopes()->find($line['product_id']);
                if ($product?->has_stock) {
                    throw new \DomainException('A goods line needs a sales order. Without one, an invoice can only bill a service.');
                }
            }

            if (($line['description'] ?? '') === '' || empty($line['revenue_account_id'])
                || ! array_key_exists('quantity', $line) || ! array_key_exists('unit_price', $line)) {
                throw new \DomainException('A service line needs a description, a quantity, a unit price, and a revenue account.');
            }
        }
    }

    private function assertServiceInvoice(CustomerInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->sales_order_line_id || ($line->product && $line->product->has_stock)) {
                throw new \DomainException('A goods line needs a sales order. Without one, an invoice can only bill a service.');
            }
            if (! $line->revenue_account_id) {
                throw new \DomainException('A service line needs a description, a quantity, a unit price, and a revenue account.');
            }
        }
    }

    private function assertOrderMatchesInvoice(int $companyId, array $data, array $lines): void
    {
        $so = SalesOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($data['sales_order_id'] ?? null);

        if (! $so) {
            throw new \DomainException('The selected Sales Order was not found for this company.');
        }

        if (isset($data['partner_id']) && (int) $so->partner_id !== (int) $data['partner_id']) {
            throw new \DomainException("Sales Order [{$so->so_code}] belongs to a different customer than the one selected on this invoice.");
        }

        $lineIds = collect($lines)->pluck('sales_order_line_id')->filter()->unique()->values();
        if ($lineIds->isNotEmpty()) {
            $valid = SalesOrderLine::withoutGlobalScopes()
                ->where('sales_order_id', $so->id)
                ->whereIn('id', $lineIds)
                ->count();
            if ($valid !== $lineIds->count()) {
                throw new \DomainException("Every invoice line must come from Sales Order [{$so->so_code}].");
            }
        }
    }

    private function syncLines(CustomerInvoice $invoice, int $companyId, array $lines): void
    {
        $invoice->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity  = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal  = bcmul($quantity, $unitPrice, 2);
            $vatRate   = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            $soLineId = $line['sales_order_line_id'] ?? null;
            $soLineId = $soLineId === '' ? null : $soLineId;
            $soLine = $soLineId ? SalesOrderLine::find($soLineId) : null;

            $revenueAccountId = $line['revenue_account_id']
                ?? $soLine?->revenue_account_id
                ?? throw new \DomainException('Every invoice line needs a revenue account — none was provided and the Sales Order line has none either.');

            // Fix (Delivery & Revenue Recognition): product_id falls back to
            // the SO line's own product — post() needs to know
            // Product::has_stock to branch this line correctly, so it can
            // no longer be silently left null the way it could before.
            $productId = $line['product_id'] ?? $soLine?->product_id;

            CustomerInvoiceLine::create([
                'company_id'           => $companyId,
                'customer_invoice_id'  => $invoice->id,
                'sales_order_line_id'  => $soLineId,
                'product_id'           => $productId,
                'description'          => $line['description'],
                'quantity'             => $quantity,
                'uom_id'               => $line['uom_id'] ?? null,
                'unit_price'           => $unitPrice,
                'line_subtotal'        => $subtotal,
                'vat_rate'             => $vatRate,
                'vat_amount'           => $vatAmount,
                'line_total'           => $lineTotal,
                'revenue_account_id'   => $revenueAccountId,
                'cost_center_id'       => $line['cost_center_id'] ?? null,
                // Audit H18: optional service period — spreads the revenue.
                'service_period_start' => ($line['service_period_start'] ?? null) ?: null,
                'service_period_end'   => ($line['service_period_end'] ?? null) ?: null,
                'sort_order'           => $i + 1,
            ]);
        }
    }

    private function recalculateTotals(CustomerInvoice $invoice): void
    {
        $lines = $invoice->lines()->get();

        $subtotal = '0';
        $vat      = '0';
        // Audit M10 — the ledger posts each line converted on its own
        // (rounded to 2 places), so the document's functional totals are
        // the SUM of those same per-line figures. Converting the total
        // once could differ by 0.01 and make the entry "not balance".
        $subtotalFunctional = '0';
        $vatFunctional      = '0';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $vat      = bcadd($vat, (string) $line->vat_amount, 2);
            $subtotalFunctional = bcadd($subtotalFunctional, bcmul((string) $line->line_subtotal, (string) $invoice->exchange_rate, 2), 2);
            $vatFunctional      = bcadd($vatFunctional, bcmul((string) $line->vat_amount, (string) $invoice->exchange_rate, 2), 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $invoice->update([
            'subtotal_amount'             => $subtotal,
            'vat_amount'                  => $vat,
            'invoice_value'               => $total,
            'subtotal_amount_functional'  => $subtotalFunctional,
            'vat_amount_functional'       => $vatFunctional,
            'invoice_value_functional'    => bcadd($subtotalFunctional, $vatFunctional, 2),
        ]);
    }

    private function generateInvoiceCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\CustomerInvoice::class, 'invoice_code', 'CI', $date);
    }
}
