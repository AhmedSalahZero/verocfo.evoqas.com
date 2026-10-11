<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerPaymentService
//
//  Customer Collection redesign (July 2026) — see
//  VeroCFO_Customer_Collection_Design_v1_0.md, the authoritative
//  reference for every decision below.
//
//  ONE ATOMIC ACTION — record(). Unlike Customer Invoice/Supplier
//  Bill, there is no draft-then-post two-step: the screenshots this
//  design was built from show a single Save action. record() creates
//  the collection, resolves wherever the money actually landed,
//  posts the journal entry, and — if any Settlement lines were
//  submitted — applies them to invoices, all inside one transaction.
//
//  DESTINATION RESOLUTION (design doc Sections 3, 4, 6) — branches on
//  money_type:
//    bank_deposit / incoming_transfer → destination_type picks either
//      a BankAccount (Current) or a CreditFacility (Overdraft) —
//      confirmed by Mahmoud that depositing into an OD pays its
//      balance down directly (Dr 223xxx / Cr AR), not a bank asset
//      account.
//    cash_in_safe → safe_account_id directly (a 131xxx Chart of
//      Accounts sub-account — Safes have no operational table of
//      their own in this app).
//    cheque → always 134001 Notes Receivable. The cheque itself is
//      NOT cash yet (design doc Section 6) — its own onward journey
//      (sent for collection / collected / rejected) lives in
//      ChequeReceivableService, not here. record() only creates the
//      ChequeReceivable row in its initial 'received' state.
//
//  SETTLEMENT / DOWN PAYMENT — per the design doc's core insight
//  (Section 1), these are the SAME journal entry shape
//  (`Dr destination / Cr AR`, posted in full). collection_type only
//  controls what the UI captures (invoice picks vs. a Contract/SO
//  tag) — the underlying allocation mechanism
//  (CustomerPaymentAllocation, allocate()/unallocate()) is completely
//  unchanged from the original build and is reused for BOTH: a
//  Settlement applies its rows immediately inside record(); a Down
//  Payment simply leaves everything unallocated for
//  allocate() to be called later via the separate "Apply Down
//  Payment" action. Any unsettled remainder on a Settlement
//  collection becomes an implicit down payment automatically — no
//  separate transaction needed (design doc Section 7).
//
//  AR ACCOUNT SPLITTING — a single collection can in principle settle
//  invoices in different AR classifications (133001 Local vs 133002
//  Export) if the invoices themselves were raised that way. The
//  credit side of the journal entry is therefore built as a bucket
//  per resolved AR account (mirrors the exact bucket pattern already
//  used in CustomerInvoiceService/CreditNoteService), not a single
//  hardcoded account.
//
//  CROSS-CURRENCY MATH — Settlement Amounts are entered in Invoice
//  Currency (matching what the Settlement Information rows display).
//  They are converted to functional currency using EACH INVOICE'S
//  OWN exchange_rate (captured at invoice-issuance time) — NOT
//  invoice_to_receive_exchange_rate, which is a different, separate
//  conversion (Receive Currency ↔ Invoice Currency, used only to
//  compute how much of the received cash corresponds to how much
//  invoice-currency exposure, for the settlement UI). Realized FX
//  gain/loss between an invoice's own rate and the collection's rate
//  is deliberately not booked here — same flagged simplification as
//  CustomerInvoiceService's original design (design doc Section 5).
//
//  AUDIT H7 FIX (Sept 2026): every settlement line is checked —
//  same partner, posted, matching currency, positive amount — see
//  assertSettleable(). The later allocate() path already did this.
//
//  @layer    Service
//  @depends  CustomerPayment, CustomerPaymentAllocation, CustomerInvoice,
//            ChequeReceivable, ChartOfAccount, CompanyActiveCurrency,
//            FinancialInstitutions\BankAccount, FinancialInstitutions\CreditFacility,
//            JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

//  v1.2 — Reopen-to-Draft (August 2026, confirmed with Mahmoud —
//  standard ERP behavior: a posted document can be reopened and
//  edited, not just viewed, as long as its period is still open).
//  record()'s posting logic (destination resolution, settlement
//  buckets, JE posting, cheque creation) is extracted into
//  postPaymentTransaction() so it's shared between record() (brand
//  new) and update() (editing a reopened draft, then re-posting).
//  setToDraft() reverses everything record()/update() posted and
//  puts the row back in STATUS_DRAFT for editing. cancel() is also
//  fixed here — it previously marked a payment 'cancelled' without
//  ever reversing its journal entry, leaving the GL and the payment
//  record disagreeing about whether the money was ever received.
//
//  @depends  ..., FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequeReceivable;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerPaymentService
{
    use \App\Services\Accounting\Concerns\SplitsChequeBatch;

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private ChequeReceivableService $chequeReceivableService,
        private FacilitySettlementEngine $settlementEngine,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CustomerPayment::with(['partner:id,name_en,name_ar', 'receiveCurrency:id,code', 'cheque', 'cheques', 'allocations'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('payment_code', 'like', "%{$s}%"))
            ->when($filters['collection_type'] ?? null, fn ($q, $t) => $q->where('collection_type', $t))
            ->when($filters['money_type'] ?? null, fn ($q, $t) => $q->where('money_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): CustomerPayment
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $settlements = $data['settlements'] ?? [];
            unset($data['settlements']);

            $data['company_id']         = $companyId;
            $data['created_by']         = $actor->id;
            $data['payment_code']       = $this->generatePaymentCode($companyId, $data['payment_date'] ?? null);
            $data['status']             = CustomerPayment::STATUS_POSTED;
            $data['exchange_rate']      = app(FxService::class)->resolveRate(
                $companyId,
                ($data['receive_currency_id'] ?? null) ? (int) ($data['receive_currency_id'] ?? null) : null,
                substr((string) ($data['payment_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional']  = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['allocated_amount']   = '0';
            $data['unallocated_amount'] = $data['amount_functional'];
            $data['posted_by']          = $actor->id;
            $data['posted_at']          = now();

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment = CustomerPayment::create($data);

            $this->postPaymentTransaction($companyId, $payment, $settlements, $chequeData, $actor);

            AuditLog::record($actor, 'customer_payment.recorded', $payment, null, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'receiveCurrency', 'invoiceCurrency', 'cheque', 'cheques', 'allocations.customerInvoice', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) collection and immediately re-posts it —
     * mirroring record() exactly, just on an existing row instead of a
     * new one. Only ever callable on a draft (setToDraft() is the only
     * way a posted collection becomes one again).
     */
    public function update(int $companyId, CustomerPayment $payment, array $data, User $actor): CustomerPayment
    {
        if ($payment->status !== CustomerPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft collection can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($companyId, $payment, $data, $actor) {
            $before = $payment->toArray();

            $settlements = $data['settlements'] ?? [];
            unset($data['settlements']);

            $data['exchange_rate']      = app(FxService::class)->resolveRate(
                $companyId,
                ($data['receive_currency_id'] ?? $payment->receive_currency_id) ? (int) ($data['receive_currency_id'] ?? $payment->receive_currency_id) : null,
                substr((string) ($data['payment_date'] ?? $payment->payment_date ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional']  = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['allocated_amount']   = '0';
            $data['unallocated_amount'] = $data['amount_functional'];
            $data['status']             = CustomerPayment::STATUS_POSTED;
            $data['posted_by']          = $actor->id;
            $data['posted_at']          = now();
            $data['updated_by']         = $actor->id;

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment->update($data);

            $this->postPaymentTransaction($companyId, $payment, $settlements, $chequeData, $actor);

            AuditLog::record($actor, 'customer_payment.reposted', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'receiveCurrency', 'invoiceCurrency', 'cheque', 'cheques', 'allocations.customerInvoice', 'journalEntry.lines']);
        });
    }

    /**
     * The actual posting mechanics — destination resolution, AR
     * settlement buckets, the journal entry itself, and cheque
     * creation. Shared between record() (brand new) and update()
     * (a reopened draft being re-posted), so the two can never quietly
     * drift apart into two different postings for what's supposed to
     * be the exact same transaction shape.
     */
    private function postPaymentTransaction(int $companyId, CustomerPayment $payment, array $settlements, ?array $chequeData, User $actor): void
    {
        $destinationAccount = $this->resolveDestinationAccount($payment);

        // Invoice Currency → Receive Currency → Functional, chained.
        // Both rates default to 1 when not applicable (same-currency
        // legs), so this collapses cleanly to "no FX" for the vast
        // majority of collections — same currency throughout, rate
        // unchanged since the invoice was raised.
        $invoiceToReceiveRate = $payment->invoice_to_receive_exchange_rate ? (string) $payment->invoice_to_receive_exchange_rate : '1';
        $receiveToFunctionalRate = $payment->exchange_rate ? (string) $payment->exchange_rate : '1';
        $collectionRateToFunctional = bcmul($invoiceToReceiveRate, $receiveToFunctionalRate, 10);

        $creditBuckets = []; // chart_of_account_id => functional amount (AR clearing — settlement + WHT)
        $createdAllocationIds = [];
        $whtTotalFunctional = '0';
        $fxGainTotal = '0';
        $fxLossTotal = '0';
        $totalCashUsedFunctional = '0'; // settlement portion only, valued at TODAY's collection rate — WHT is never cash

        foreach ($settlements as $line) {
            $invoice = CustomerInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($line['customer_invoice_id']);
            $settlementNative = (string) $line['settlement_amount'];

            // Audit H7: the same checks allocate() already makes — the
            // invoice must be this customer's own, posted, in this
            // collection's invoice currency, and the amount positive.
            // Before, a collection from Customer A could settle
            // Customer B's invoice through this path.
            $this->assertSettleable($payment, $invoice, $settlementNative, (string) ($line['wht_amount'] ?? '0'));
            $whtNative = (string) ($line['wht_amount'] ?? '0');

            // Both cleared against AR at the INVOICE's own booked rate —
            // this is what actually reduces the exact functional-currency
            // AR balance that was recorded at invoice time. WHT is a
            // paper certificate amount, not something re-valued by
            // market rate movement, so it uses the same rate as the
            // settlement it rides alongside.
            $arClearFromSettlement = bcmul($settlementNative, (string) $invoice->exchange_rate, 2);
            $arClearFromWht        = bcmul($whtNative, (string) $invoice->exchange_rate, 2);
            $totalArClear          = bcadd($arClearFromSettlement, $arClearFromWht, 2);

            if (bccomp($totalArClear, (string) $invoice->amount_outstanding, 2) > 0) {
                $rate = bccomp((string) $invoice->exchange_rate, '0', 6) > 0 ? (string) $invoice->exchange_rate : '1';
                $outstandingNative = bcdiv((string) $invoice->amount_outstanding, $rate, 2);
                throw new \DomainException(
                    "Settlement + WHT amount exceeds what's still outstanding on Invoice {$invoice->invoice_code}. ".
                    "Settlement Amount is entered in the invoice's own currency — the maximum to fully settle it is {$outstandingNative}."
                );
            }

            // The cash portion's REAL functional value, at today's actual
            // collection rate — this is what the money the customer
            // handed over is genuinely worth right now, which can differ
            // from what the invoice was originally booked at.
            $collectionCashFunctional = bcmul($settlementNative, $collectionRateToFunctional, 2);
            $totalCashUsedFunctional = bcadd($totalCashUsedFunctional, $collectionCashFunctional, 2);

            $fxDiff = bcsub($collectionCashFunctional, $arClearFromSettlement, 2);
            if (bccomp($fxDiff, '0', 2) > 0) {
                $fxGainTotal = bcadd($fxGainTotal, $fxDiff, 2);
            } elseif (bccomp($fxDiff, '0', 2) < 0) {
                $fxLossTotal = bcadd($fxLossTotal, bcmul($fxDiff, '-1', 2), 2);
            }

            $arAccount = $this->resolveArAccount($companyId, $invoice->currency_id);
            $this->addToBucket($creditBuckets, $arAccount->id, $totalArClear);
            $whtTotalFunctional = bcadd($whtTotalFunctional, $arClearFromWht, 2);

            $allocation = CustomerPaymentAllocation::create([
                'company_id'               => $companyId,
                'customer_payment_id'      => $payment->id,
                'customer_invoice_id'      => $invoice->id,
                'allocated_amount'         => $arClearFromSettlement,
                'wht_amount'               => $whtNative,
                'wht_amount_functional'    => $arClearFromWht,
                'fx_gain_loss_functional'  => $fxDiff,
                'created_by'               => $actor->id,
            ]);
            $createdAllocationIds[] = $allocation->id;

            $invoice->update([
                'amount_paid'           => bcadd((string) $invoice->amount_paid, $arClearFromSettlement, 2),
                'amount_wht_deducted'   => bcadd((string) $invoice->amount_wht_deducted, $arClearFromWht, 2),
                'amount_outstanding'    => bcsub((string) $invoice->amount_outstanding, $totalArClear, 2),
            ]);

            $payment->increment('allocated_amount', $arClearFromSettlement);
            $payment->decrement('unallocated_amount', $arClearFromSettlement);
        }

        // Fix (Customer Collection v1.1 — the accounting error this
        // version corrects): whatever's left unsettled is NOT
        // AR — nothing has been invoiced for it. It credits 235
        // Advances from Customers instead — a real liability,
        // whether it's a deliberately-tagged Down Payment or
        // simply an over-collection beyond what Settlement needed
        // (design doc Section 1). No separate transaction needed;
        // it's the same entry, just a different credit bucket.
        //
        // Compared against the CASH actually used for settlements
        // (totalCashUsedFunctional, at today's rate) — not the AR-clearing
        // total — since WHT and any FX gain/loss are not part of the
        // real cash pool this payment brought in.
        $remainder = bcsub((string) $payment->amount_functional, $totalCashUsedFunctional, 2);
        if (bccomp($remainder, '0', 2) < 0) {
            throw new \DomainException('Total settlement amounts (at today\'s collection rate) exceed the amount actually received.');
        }

        $lines = [
            ['chart_of_account_id' => $destinationAccount->id, 'debit' => (string) $payment->amount_functional, 'credit' => '0', 'description' => 'Customer Collection '.$payment->payment_code],
        ];

        if (bccomp($whtTotalFunctional, '0', 2) > 0) {
            $whtAccount = $this->resolveDebitWhtReceivableAccount($companyId);
            $lines[] = ['chart_of_account_id' => $whtAccount->id, 'debit' => $whtTotalFunctional, 'credit' => '0', 'description' => 'Debit WHT withheld — '.$payment->payment_code];
        }

        if (bccomp($fxLossTotal, '0', 2) > 0) {
            $fxLossAccount = $this->resolveRealizedFxLossAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxLossAccount->id, 'debit' => $fxLossTotal, 'credit' => '0', 'description' => 'Realized FX Loss — '.$payment->payment_code];
        }

        foreach ($creditBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Customer Collection '.$payment->payment_code];
        }

        if (bccomp($remainder, '0', 2) > 0) {
            $advancesAccount = $this->resolveAdvancesFromCustomersAccount($companyId);
            $lines[] = ['chart_of_account_id' => $advancesAccount->id, 'debit' => '0', 'credit' => $remainder, 'description' => 'Customer Collection '.$payment->payment_code];
        }

        if (bccomp($fxGainTotal, '0', 2) > 0) {
            $fxGainAccount = $this->resolveRealizedFxGainAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxGainAccount->id, 'debit' => '0', 'credit' => $fxGainTotal, 'description' => 'Realized FX Gain — '.$payment->payment_code];
        }

        $journalEntry = $this->journalEntryService->post(
            companyId: $companyId,
            entryDate: $payment->payment_date,
            lines: app(FxService::class)->tagLines($lines, (int) $payment->receive_currency_id, $payment->exchange_rate), // Audit H12/H15
            sourceType: 'customer_payment',
            sourceId: $payment->id,
            narration: $this->narrationFor($payment),
            actor: $actor,
        );

        $payment->update([
            'journal_entry_id'          => $journalEntry->id,
            'fx_gain_loss_functional'   => bcsub($fxGainTotal, $fxLossTotal, 2),
        ]);

        // Facility Settlement Engine (Bible Rule 34) — the journal
        // entry above already paid the facility down for real (it's
        // one of the debit lines built into $lines). This only
        // decides which charge/withdrawal that money actually clears,
        // in Rule 34.2 priority order — never a second journal entry.
        if ($payment->destination_type === CustomerPayment::DESTINATION_CREDIT_FACILITY) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($payment->destination_credit_facility_id);

            if (! $facility->bank_account_id) {
                throw new \DomainException('This facility has no linked bank account — cannot record a settlement against it.');
            }

            // Currency bug fix (2026-09) — see the matching note in
            // SupplierPaymentService::postPaymentTransaction(). The
            // facility's own outstanding_balance is tracked in its own
            // currency; receive_currency_id is what this collection was
            // actually received in, and it must match.
            if ((int) $payment->receive_currency_id !== (int) $facility->currency_id) {
                throw new \DomainException(
                    "This payment's currency doesn't match the facility's own currency — ".
                    'convert to the facility currency first, then deposit into it.'
                );
            }

            $this->settlementEngine->recordSettlement($facility, [
                'bank_account_id'  => $facility->bank_account_id,
                'settlement_date'  => $payment->payment_date,
                'amount'           => $payment->amount,
                'source_type'      => FacilitySettlement::SOURCE_CUSTOMER_PAYMENT,
                'source_id'        => $payment->id,
                'journal_entry_id' => $journalEntry->id,
            ], $actor);
        }

        // Backfill — these allocations are part of THIS SAME entry
        // (their AR credit is one of the buckets above), not a
        // separate Dr 235/Cr AR movement like a later
        // allocate() call. Still worth recording which entry
        // created each allocation, for traceability either way.
        if ($createdAllocationIds) {
            CustomerPaymentAllocation::whereIn('id', $createdAllocationIds)->update(['journal_entry_id' => $journalEntry->id]);
        }

        if ($payment->isCheque()) {
            $this->createChequeReceivable($payment, $chequeData, $actor);
        }
    }

    /**
     * Reopens a posted collection back to draft — the ONLY way a
     * posted CustomerPayment becomes editable again. Automatically
     * reverses EVERYTHING it did — its own journal entry AND every
     * invoice application it made, including ones added later via the
     * separate "Apply" action (confirmed with Mahmoud: this must be
     * fully automatic, never a manual "unallocate first" pre-step the
     * user has to go hunt for) — then resets the row to a clean draft.
     * update() is the only way to re-post it, with a brand new journal
     * entry reflecting whatever settlements the person picks this time.
     *
     * Blocked if:
     *   - it's already draft or cancelled (nothing to reopen)
     *   - its cheque has moved past 'received' (something downstream
     *     already depends on it existing)
     *   - its accounting period is closed (Rule: closed periods allow
     *     no postings, no edits, no reversals — first real enforcement
     *     of this in the app; see FiscalYearService::isPeriodClosed())
     */
    public function setToDraft(CustomerPayment $payment, User $actor): CustomerPayment
    {
        if ($payment->status !== CustomerPayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted collection can be set back to draft.');
        }
        $this->assertReversible($payment, 'reopened');
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            $this->reverseEverything($payment, $actor, 'Reopened to draft — '.$payment->payment_code);

            $payment->update([
                'status'             => CustomerPayment::STATUS_DRAFT,
                'journal_entry_id'   => null,
                'posted_by'          => null,
                'posted_at'          => null,
                'allocated_amount'   => '0',
                'unallocated_amount' => $payment->amount_functional,
                'fx_gain_loss_functional' => '0',
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'customer_payment.set_to_draft', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    /**
     * Shared by setToDraft() and cancel() — every "undo everything real
     * this collection did" step, kept in exactly one place so the two
     * can never quietly drift apart on what counts as fully reversed.
     * Confirmed with Mahmoud: none of this should ever require a
     * manual pre-step on a different screen — invoice applications and
     * the cheque's own state (if Sent for Collection or Collected) are
     * both unwound automatically, in this same action.
     *
     * Does NOT touch the payment's own status/journal_entry_id fields —
     * callers do that themselves afterward, since setToDraft() and
     * cancel() end in different states (draft vs. a dead-end cancelled).
     */
    private function reverseEverything(CustomerPayment $payment, User $actor, string $reversalNarration): void
    {
        $mainJournalEntryId = $payment->journal_entry_id;

        // Facility Settlement Engine — undo the settlement this
        // payment created, before reversing the journal entry. If
        // any of what it paid off has itself since been reversed or
        // resettled, reverseSettlement() throws a clear domain
        // exception rather than leaving things half-undone.
        if ($payment->destination_type === CustomerPayment::DESTINATION_CREDIT_FACILITY) {
            $settlement = FacilitySettlement::where('source_type', FacilitySettlement::SOURCE_CUSTOMER_PAYMENT)
                ->where('source_id', $payment->id)
                ->posted()
                ->first();

            if ($settlement) {
                $this->settlementEngine->reverseSettlement($settlement, $actor);
            }
        }

        // Undo every invoice application automatically. A Settlement
        // allocation made at record()/update() time shares the SAME
        // journal entry as the collection itself (one entry, several
        // credit lines) — reversing the main entry below already
        // undoes those. A LATER "Apply" allocation (allocate())
        // posts its own separate Dr 235/Cr AR entry, which must be
        // reversed here individually, or it would be left standing.
        foreach ($payment->allocations()->lockForUpdate()->get() as $allocation) {
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($allocation->customer_invoice_id);
            $amount  = (string) $allocation->allocated_amount;
            $whtAmount = (string) $allocation->wht_amount_functional;
            $totalArClear = bcadd($amount, $whtAmount, 2);

            if ($allocation->journal_entry_id && $allocation->journal_entry_id !== $mainJournalEntryId) {
                $entry = JournalEntry::findOrFail($allocation->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, $reversalNarration.' from Invoice '.$invoice->invoice_code);
            }

            $invoice->update([
                'amount_paid'          => bcsub((string) $invoice->amount_paid, $amount, 2),
                'amount_wht_deducted'  => bcsub((string) $invoice->amount_wht_deducted, $whtAmount, 2),
                'amount_outstanding'   => bcadd((string) $invoice->amount_outstanding, $totalArClear, 2),
            ]);

            $allocation->delete();
        }

        if ($mainJournalEntryId) {
            $entry = JournalEntry::findOrFail($mainJournalEntryId);
            $this->journalEntryService->reverse($entry, $actor, $reversalNarration);
        }

        // Cascade-unwind the cheque itself automatically. If it was
        // actually Collected, reverse the REAL cash journal entry it
        // posted first (reusing ChequeReceivableService's own tested
        // reversal, not a second copy of that logic) — never silently
        // leaving an unreversed journal entry behind just because the
        // cheque row is about to disappear.
        if ($payment->isCheque()) {
            foreach ($payment->cheques as $c) {
                if ($c->isCollected()) {
                    $this->chequeReceivableService->undoCollect($c, $actor);
                }
                $c->delete();
            }
        }
    }

    /**
     * Shared guard for setToDraft() and cancel(). 'rejected' is
     * deliberately still blocked — a bounced cheque already reopened AR
     * through reject()'s own journal entry, and unwinding the original
     * collection at the same time creates a genuine ambiguity about
     * what AR should end up at. Needs Mahmoud's explicit design
     * decision before automating, unlike 'sent_for_collection'/
     * 'collected', which are safe and unambiguous to cascade
     * automatically in reverseEverything() above.
     */
    private function assertReversible(CustomerPayment $payment, string $verb): void
    {
        if ($payment->isCheque() && $payment->cheques->contains(fn ($c) => $c->isRejected())) {
            throw new \DomainException("This cheque was rejected — {$verb} the collection it came from needs a manual decision, not an automatic reversal.");
        }
    }

    /**
     * Deletes a collection outright — the ONLY way this is allowed is
     * on a draft (never a posted one), and the only way a collection
     * becomes a draft is via setToDraft(), which has already fully
     * reversed everything real it did — its journal entry, every
     * invoice application, its cheque record. By the time a collection
     * reaches this point it's a harmless empty shell with zero
     * financial residue, so a genuine hard delete is safe and
     * consistent with the "no hard deletes on financial data" rule —
     * the rule protects records that still represent real money;
     * this one no longer does. Same two-step philosophy as Facility
     * Renewal's canBeDeleted() (peel back to a safe state first, only
     * then allow deletion) — not a special exception invented here.
     *
     * Defensive guards below re-check what setToDraft() should have
     * already guaranteed, rather than trusting that silently.
     */
    public function delete(CustomerPayment $payment, User $actor): void
    {
        if ($payment->status !== CustomerPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft collection can be deleted — use "Set to Draft" first.');
        }
        if ($payment->journal_entry_id) {
            throw new \DomainException('This collection still has a journal entry attached — it cannot be deleted.');
        }
        if (! $payment->allocations->isEmpty()) {
            throw new \DomainException('This collection still has invoice applications attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            if ($payment->isCheque()) {
                $payment->cheques->each->delete();
            }

            $payment->delete();

            AuditLog::record($actor, 'customer_payment.deleted', $payment, $before, null);
        });
    }

    /**
     * Fix (August 2026): previously marked the payment 'cancelled'
     * WITHOUT ever reversing its journal entry — the GL would still
     * show the cash received while the payment record claimed it was
     * cancelled. Now genuinely reverses, same as setToDraft().
     * Difference from setToDraft(): this is a dead end (STATUS_CANCELLED,
     * no further action possible), used when the collection was simply
     * wrong and should never have existed — not when it needs editing
     * and re-posting.
     */
    public function cancel(CustomerPayment $payment, User $actor): CustomerPayment
    {
        // Fix (August 2026): previously required the person to manually
        // unallocate every invoice application first, on a different
        // action, before cancel() would even run — the exact same
        // "manual pre-step" problem already fixed once for setToDraft().
        // Now shares reverseEverything(), so it's automatic here too.
        $this->assertReversible($payment, 'cancelling');
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            $this->reverseEverything($payment, $actor, 'Cancelled — '.$payment->payment_code);

            $payment->update([
                'status'             => CustomerPayment::STATUS_CANCELLED,
                'allocated_amount'   => '0',
                'unallocated_amount' => $payment->amount_functional,
                'fx_gain_loss_functional' => '0',
                'updated_by'         => $actor->id,
            ]);
            AuditLog::record($actor, 'customer_payment.cancelled', $payment, $before, $payment->fresh()->toArray());
            return $payment->fresh();
        });
    }

    // ── Allocate / Unallocate — corrected in v1.1: now posts a real entry ──

    /**
     * Applies previously-unapplied cash (235 Advances from Customers)
     * against a real invoice. This is now a genuine account-to-account
     * movement (Dr 235 / Cr AR) — see design doc Section 1 — not a
     * re-labeling of cash that was already sitting in AR. Always
     * creates a NEW allocation row (even if this payment/invoice pair
     * already has one) so every row maps cleanly to exactly one
     * journal entry — a clean matching history, not a merged running
     * total that would need to track several entries per row.
     */
    public function allocate(CustomerPayment $payment, CustomerInvoice $invoice, string $invoiceCurrencyAmount, User $actor, ?string $applicationDate = null): CustomerPaymentAllocation
    {
        if (! $payment->isPosted()) {
            throw new \DomainException('Only a posted collection can be allocated.');
        }
        if (! $invoice->isPosted()) {
            throw new \DomainException('Can only allocate against a posted Customer Invoice.');
        }
        if ($payment->partner_id !== $invoice->partner_id) {
            throw new \DomainException('This collection and invoice belong to different customers.');
        }

        // AUDIT M-12 — the advance sits in 235 at the rate it was RECEIVED
        // at; the invoice sits in AR at the rate it was RAISED at. Applying
        // one against the other used to take both at the invoice rate,
        // dated today, so the rate difference was never recognised and
        // stayed behind in 235. Now: 235 is relieved at the advance's own
        // rate, AR at the invoice's rate, and the difference goes to
        // realised FX gain / loss, exactly as a direct settlement does.
        $advanceCurrencyId = $payment->invoice_currency_id ?: $payment->receive_currency_id;
        if ($advanceCurrencyId && $invoice->currency_id && (int) $advanceCurrencyId !== (int) $invoice->currency_id) {
            throw new \DomainException('This advance is held in a different currency from the invoice — apply it to an invoice in the same currency.');
        }

        $invoiceToReceive  = $payment->invoice_to_receive_exchange_rate ? (string) $payment->invoice_to_receive_exchange_rate : '1';
        $receiveToFunc     = $payment->exchange_rate ? (string) $payment->exchange_rate : '1';
        $advanceRate       = bcmul($invoiceToReceive, $receiveToFunc, 10);
        $advanceFunctional = bcmul($invoiceCurrencyAmount, $advanceRate, 2);   // leaves 235
        $arClear           = bcmul($invoiceCurrencyAmount, (string) $invoice->exchange_rate, 2); // leaves AR
        $fxDiff            = bcsub($advanceFunctional, $arClear, 2);           // + gain / − loss

        if (bccomp($arClear, '0', 2) <= 0 || bccomp($advanceFunctional, '0', 2) <= 0) {
            throw new \DomainException('Allocation amount must be greater than zero.');
        }
        if (bccomp($advanceFunctional, (string) $payment->unallocated_amount, 2) > 0) {
            throw new \DomainException("Allocation exceeds what's still unallocated on this collection ({$payment->unallocated_amount}).");
        }
        if (bccomp($arClear, (string) $invoice->amount_outstanding, 2) > 0) {
            throw new \DomainException("Allocation exceeds what's still outstanding on Invoice {$invoice->invoice_code} ({$invoice->amount_outstanding}).");
        }

        // AUDIT M-12 — the application is dated by the caller (default
        // today), never before either document existed.
        $entryDate = $applicationDate ? substr($applicationDate, 0, 10) : now()->toDateString();
        foreach (['collection' => $payment->payment_date, 'invoice' => $invoice->invoice_date] as $label => $docDate) {
            if ($docDate && $entryDate < substr((string) $docDate, 0, 10)) {
                throw new \DomainException("The application date cannot be before the {$label} date (".substr((string) $docDate, 0, 10).').');
            }
        }

        return DB::transaction(function () use ($payment, $invoice, $advanceFunctional, $arClear, $fxDiff, $entryDate, $actor) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($payment->id);
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($invoice->id);

            $advancesAccount = $this->resolveAdvancesFromCustomersAccount($payment->company_id);
            $arAccount = $this->resolveArAccount($payment->company_id, $invoice->currency_id);
            $memo = 'Down payment applied — '.$payment->payment_code.' → '.$invoice->invoice_code;

            $lines = [
                ['chart_of_account_id' => $advancesAccount->id, 'debit' => $advanceFunctional, 'credit' => '0', 'description' => $memo],
                ['chart_of_account_id' => $arAccount->id, 'debit' => '0', 'credit' => $arClear, 'description' => $memo],
            ];
            if (bccomp($fxDiff, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $this->resolveRealizedFxGainAccount($payment->company_id)->id, 'debit' => '0', 'credit' => $fxDiff, 'description' => 'Realized FX Gain — '.$memo];
            } elseif (bccomp($fxDiff, '0', 2) < 0) {
                $lines[] = ['chart_of_account_id' => $this->resolveRealizedFxLossAccount($payment->company_id)->id, 'debit' => bcmul($fxDiff, '-1', 2), 'credit' => '0', 'description' => 'Realized FX Loss — '.$memo];
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $payment->company_id,
                entryDate: $entryDate,
                lines: $lines,
                sourceType: 'customer_payment_allocation',
                sourceId: $payment->id,
                narration: "Applied {$payment->payment_code} against Invoice {$invoice->invoice_code} — {$invoice->partner->name_en}",
                actor: $actor,
            );

            // allocated_amount is the AR side (what the invoice was
            // reduced by) — the same meaning it has on a direct
            // settlement — so statements and revaluation read it unchanged.
            $allocation = CustomerPaymentAllocation::create([
                'company_id'              => $payment->company_id,
                'customer_payment_id'     => $payment->id,
                'customer_invoice_id'     => $invoice->id,
                'allocated_amount'        => $arClear,
                'fx_gain_loss_functional' => $fxDiff,
                'journal_entry_id'        => $journalEntry->id,
                'created_by'              => $actor->id,
            ]);

            $payment->update([
                'allocated_amount'        => bcadd((string) $payment->allocated_amount, $advanceFunctional, 2),
                'unallocated_amount'      => bcsub((string) $payment->unallocated_amount, $advanceFunctional, 2),
                'fx_gain_loss_functional' => bcadd((string) $payment->fx_gain_loss_functional, $fxDiff, 2),
            ]);

            $invoice->update([
                'amount_paid'         => bcadd((string) $invoice->amount_paid, $arClear, 2),
                'amount_outstanding'  => bcsub((string) $invoice->amount_outstanding, $arClear, 2),
            ]);

            AuditLog::record($actor, 'customer_payment.allocated', $payment, null, ['invoice' => $invoice->invoice_code, 'amount' => $arClear, 'advance_released' => $advanceFunctional, 'fx' => $fxDiff, 'date' => $entryDate]);

            return $allocation->fresh(['customerPayment', 'customerInvoice', 'journalEntry.lines']);
        });
    }

    /**
     * Reverses a previously-applied allocation — including reversing
     * its own journal entry (Dr AR / Cr 235, the mirror image of what
     * allocate() posted), not just deleting the matching row.
     *
     * Fix (August 2026): guards against a real bug — a Settlement-type
     * allocation (created inside record()/update(), NOT via a separate
     * allocate() call) shares its journal_entry_id with the collection's
     * OWN main entry — it's a credit LINE within that entry, not a
     * separate one. Calling reverse() on it here would reverse the
     * entire multi-line entry, silently undoing far more than "just
     * this one invoice." Those must go through setToDraft() instead,
     * which correctly reverses the whole entry as one unit. Only an
     * allocation with its own distinct entry (a later "Apply" action)
     * can be safely unapplied on its own.
     */
    public function unallocate(CustomerPaymentAllocation $allocation, User $actor): void
    {
        $payment = CustomerPayment::findOrFail($allocation->customer_payment_id);

        if ($allocation->journal_entry_id && $allocation->journal_entry_id === $payment->journal_entry_id) {
            throw new \DomainException(
                'This application was part of the collection\'s original posting, not a separate one — use "Set to Draft" on the collection itself to undo it.'
            );
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — this application cannot be reversed.');
        }

        DB::transaction(function () use ($allocation, $actor) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($allocation->customer_payment_id);
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($allocation->customer_invoice_id);
            $amount  = (string) $allocation->allocated_amount;  // AR side
            $fx      = (string) $allocation->fx_gain_loss_functional;
            $advance = bcadd($amount, $fx, 2);                  // AUDIT M-12: 235 side = AR side + FX

            if ($allocation->journal_entry_id) {
                $entry = JournalEntry::findOrFail($allocation->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Unallocated — '.$payment->payment_code.' from Invoice '.$invoice->invoice_code);
            }

            $payment->update([
                'allocated_amount'        => bcsub((string) $payment->allocated_amount, $advance, 2),
                'unallocated_amount'      => bcadd((string) $payment->unallocated_amount, $advance, 2),
                'fx_gain_loss_functional' => bcsub((string) $payment->fx_gain_loss_functional, $fx, 2),
            ]);

            $invoice->update([
                'amount_paid'         => bcsub((string) $invoice->amount_paid, $amount, 2),
                'amount_outstanding'  => bcadd((string) $invoice->amount_outstanding, $amount, 2),
            ]);

            AuditLog::record($actor, 'customer_payment.unallocated', $payment, ['invoice' => $invoice->invoice_code, 'amount' => $amount], null);

            $allocation->delete();
        });
    }

    /**
     * Unlinks an allocation that was part of the collection's ORIGINAL
     * posting — without reversing the collection itself. Fix (August
     * 2026, confirmed with Mahmoud): the old behavior forced these
     * through setToDraft(), which reversed the whole collection AND
     * deleted the cheque row outright — wiping Drawee Bank / Due Date /
     * Cheque Number / Receiving Safe even though only this one
     * invoice's link needed to go away. This method instead reverses
     * just the original entry and re-posts the same money as
     * unapplied (235 Advances from Customers) — the payment stays
     * POSTED, its cheque (and the cheque's own status — In Safe, Under
     * Collection, whatever it was) is never touched.
     *
     * Only safe when this original entry settled ONLY this one
     * invoice — if it combined several invoices into one entry
     * (shared AR credit bucket), reversing it would silently un-settle
     * the others too. That case is still blocked, same as before:
     * reopen the collection itself (Set to Draft) first.
     */
    public function releaseOriginalAllocation(CustomerPaymentAllocation $allocation, User $actor): void
    {
        $payment = CustomerPayment::findOrFail($allocation->customer_payment_id);

        if (! $allocation->journal_entry_id || $allocation->journal_entry_id !== $payment->journal_entry_id) {
            throw new \DomainException('This application is not part of the collection\'s original posting — use unallocate() instead.');
        }

        $touchesOtherInvoices = CustomerPaymentAllocation::where('customer_payment_id', $payment->id)
            ->where('id', '!=', $allocation->id)
            ->where('journal_entry_id', $payment->journal_entry_id)
            ->exists();

        if ($touchesOtherInvoices) {
            throw new \DomainException(
                "This collection's original posting also settled other invoices — reopen the collection itself (Set to Draft) first, then re-save it without this invoice."
            );
        }

        $this->assertReversible($payment, 'released');

        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — this application cannot be released.');
        }

        DB::transaction(function () use ($allocation, $actor) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($allocation->customer_payment_id);
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($allocation->customer_invoice_id);
            $amount  = (string) $allocation->allocated_amount;

            $originalEntry = JournalEntry::findOrFail($allocation->journal_entry_id);
            $this->journalEntryService->reverse(
                $originalEntry,
                $actor,
                'Released — '.$payment->payment_code.' unlinked from Invoice '.$invoice->invoice_code
            );

            // Re-post the exact same money, still sitting wherever it
            // was (Notes Receivable / Bank / Safe) — just no longer
            // tied to this invoice's AR. This is what "unapplied"
            // means: real money already received, not yet matched.
            $destinationAccount = $this->resolveDestinationAccount($payment);
            $advancesAccount    = $this->resolveAdvancesFromCustomersAccount($payment->company_id);

            $newEntry = $this->journalEntryService->post(
                companyId: $payment->company_id,
                entryDate: $payment->payment_date,
                lines: [
                    ['chart_of_account_id' => $destinationAccount->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Held as unapplied — '.$payment->payment_code,
                        'currency_id' => $payment->receive_currency_id, 'exchange_rate' => $payment->exchange_rate], // Audit H12/H15
                    ['chart_of_account_id' => $advancesAccount->id,    'debit' => '0', 'credit' => $amount, 'description' => 'Held as unapplied — '.$payment->payment_code],
                ],
                sourceType: 'customer_payment_unapplied',
                sourceId: $payment->id,
                narration: 'Released from Invoice '.$invoice->invoice_code.' — '.$payment->payment_code.' held as unapplied',
                actor: $actor,
            );

            $payment->update([
                'journal_entry_id'   => $newEntry->id,
                'allocated_amount'   => bcsub((string) $payment->allocated_amount, $amount, 2),
                'unallocated_amount' => bcadd((string) $payment->unallocated_amount, $amount, 2),
            ]);

            $invoice->update([
                'amount_paid'         => bcsub((string) $invoice->amount_paid, $amount, 2),
                'amount_outstanding'  => bcadd((string) $invoice->amount_outstanding, $amount, 2),
            ]);

            AuditLog::record($actor, 'customer_payment.original_allocation_released', $payment, ['invoice' => $invoice->invoice_code, 'amount' => $amount], null);

            $allocation->delete();
        });
    }

    // ── Private — destination resolution (design doc Sections 3, 4) ──

    private function resolveDestinationAccount(CustomerPayment $payment): ChartOfAccount
    {
        return match ($payment->money_type) {
            CustomerPayment::MONEY_BANK_DEPOSIT, CustomerPayment::MONEY_INCOMING_TRANSFER => $this->resolveBankOrFacilityAccount($payment),
            CustomerPayment::MONEY_CASH_IN_SAFE => $this->resolveSafeAccount($payment),
            CustomerPayment::MONEY_CHEQUE       => $this->resolveNotesReceivableAccount($payment),
            default => throw new \DomainException("Unknown money type: {$payment->money_type}"),
        };
    }

    private function resolveBankOrFacilityAccount(CustomerPayment $payment): ChartOfAccount
    {
        if ($payment->destination_type === CustomerPayment::DESTINATION_CREDIT_FACILITY) {
            $facility = CreditFacility::where('id', $payment->destination_credit_facility_id)
                ->where('company_id', $payment->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $payment->destination_bank_account_id)
            ->where('company_id', $payment->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The selected bank account does not belong to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveSafeAccount(CustomerPayment $payment): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($payment->company_id)
            ->where('id', $payment->safe_account_id)->first();

        return $account ?? throw new \DomainException('The selected Safe account does not belong to this company.');
    }

    private function resolveNotesReceivableAccount(CustomerPayment $payment): ChartOfAccount
    {
        return ChartOfAccount::findByCode($payment->company_id, '134001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 134001 (Notes Receivable — Customers) — check the seeder.');
    }

    private function resolveArAccount(int $companyId, int $currencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Receivable account ({$code}) is not seeded for this company — cannot post.");
    }

    /**
     * 235 Advances from Customers — Customer Collection v1.1
     * correction (design doc Section 1). Money received with no
     * invoice behind it credits here, never AR.
     */
    private function resolveAdvancesFromCustomersAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '235')
            ?? throw new \DomainException('Chart of Accounts is missing system account 235 (Advances from Customers) — check the seeder.');
    }

    /**
     * 142 Debit WHT Receivable — the customer's own withheld tax,
     * only ever hit when a Settlement row carries a Debit WHT amount
     * (see postPaymentTransaction()). Single company-wide account, no
     * currency sub-accounts — matches the Chart of Accounts design
     * (Tax Settings doc: "Postable at Level 3, no sub-accounts").
     */
    private function resolveDebitWhtReceivableAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '142')
            ?? throw new \DomainException('Chart of Accounts is missing system account 142 (Debit WHT Receivable) — check the seeder.');
    }

    /** 431 Realized FX Gain — see postPaymentTransaction()'s per-settlement-row FX calculation. */
    private function resolveRealizedFxGainAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '431')
            ?? throw new \DomainException('Chart of Accounts is missing system account 431 (Realized FX Gain) — check the seeder.');
    }

    /** 571 Realized FX Loss — see postPaymentTransaction()'s per-settlement-row FX calculation. */
    private function resolveRealizedFxLossAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '571')
            ?? throw new \DomainException('Chart of Accounts is missing system account 571 (Realized FX Loss) — check the seeder.');
    }

    /** Audit H7 — a settlement line may only touch this customer's own posted invoice, in the right currency. */
    private function assertSettleable(CustomerPayment $payment, CustomerInvoice $invoice, string $settlement, string $wht): void
    {
        if ((int) $invoice->company_id !== (int) $payment->company_id || (int) $invoice->partner_id !== (int) $payment->partner_id) {
            throw new \DomainException("Invoice {$invoice->invoice_code} belongs to a different customer — a collection can only settle its own customer's invoices.");
        }
        if (! $invoice->isPosted()) {
            throw new \DomainException("Invoice {$invoice->invoice_code} is not posted — only posted invoices can be settled.");
        }
        if ($payment->invoice_currency_id && (int) $invoice->currency_id !== (int) $payment->invoice_currency_id) {
            throw new \DomainException("Invoice {$invoice->invoice_code} is in a different currency from the one this collection is settling.");
        }
        if (! is_numeric($settlement) || bccomp($settlement, '0', 2) < 0 || ! is_numeric($wht) || bccomp($wht, '0', 2) < 0
            || bccomp(bcadd($settlement, $wht, 2), '0', 2) <= 0) {
            throw new \DomainException("Settlement on Invoice {$invoice->invoice_code} must be a positive amount.");
        }
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    private function narrationFor(CustomerPayment $payment): string
    {
        $label = $payment->isDownPayment() ? 'Down Payment' : 'Collection';
        return "Customer {$label} {$payment->payment_code} — {$payment->partner->name_en}";
    }

    private function createChequeReceivable(CustomerPayment $payment, ?array $chequeData, User $actor): void
    {
        if (! $chequeData) {
            throw new \DomainException('Cheque details are required for a Cheque collection.');
        }

        // M-31: one collection may hold a batch of post-dated cheques.
        foreach ($this->splitChequeBatch($chequeData, (string) $payment->amount, (string) $payment->amount_functional) as $row) {
            ChequeReceivable::create([
                'company_id'                => $payment->company_id,
                'customer_payment_id'       => $payment->id,
                'partner_id'                => $payment->partner_id,
                'drawee_bank_id'            => $row['drawee_bank_id'],
                'cheque_amount'             => $row['amount'],
                // Audit C8: remember the cheque's currency and the EGP value booked, so clearing it can value any FX difference.
                'currency_id'               => $payment->receive_currency_id,
                'exchange_rate'             => $payment->exchange_rate ?: 1,
                'amount_functional'         => $row['amount_functional'],
                'cheque_number'             => $row['cheque_number'],
                'due_date'                  => $row['due_date'],
                'receiving_safe_account_id' => $row['receiving_safe_account_id'],
                'status'                    => ChequeReceivable::STATUS_RECEIVED,
                'created_by'                => $actor->id,
            ]);
        }
    }

    private function generatePaymentCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\CustomerPayment::class, 'payment_code', 'CP', $date);
    }
}
