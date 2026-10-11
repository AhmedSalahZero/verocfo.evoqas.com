<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierPaymentService
//
//  Supplier Payment (August 2026) — the payable-side mirror of
//  CustomerPaymentService, confirmed by Mahmoud to follow the exact
//  same design. Every account role is reversed:
//    Customer Collection: Dr [destination] / Cr AR (or 235 Advances)
//    Supplier Payment:     Dr AP (or 144 Advances) / Cr [source]
//
//  ONE ATOMIC ACTION — record(). Same as Customer Collection: no
//  draft-then-post two-step. 'draft' is only ever reached later, by
//  reopening a posted payment (setToDraft()).
//
//  SOURCE RESOLUTION — branches on money_type (only THREE values,
//  confirmed by Mahmoud — not four like Customer Collection):
//    cash_payment       → safe_account_id (131xxx sub-account)
//    outgoing_transfer  → source_type picks a BankAccount (Current)
//                          or a CreditFacility (Overdraft) — paying
//                          an OD down directly, same as depositing
//                          into one on the customer side.
//    payable_cheque     → always 222001 Notes Payable — Suppliers.
//                          The cheque itself hasn't left our hands as
//                          real cash yet — its own onward journey
//                          (Mark as Paid / Reset) lives in
//                          ChequePayableService, not here. record()
//                          only creates the ChequePayable row in its
//                          initial 'issued' state.
//
//  SETTLEMENT / DOWN PAYMENT — identical mechanism to Customer
//  Collection: payment_type only controls what the UI captures
//  (bill picks vs. a Project/PO tag); the underlying allocation
//  mechanism (SupplierPaymentAllocation, allocate()/unallocate()) is
//  the same for both. A Settlement applies its rows immediately
//  inside record(); a Down Payment leaves everything unallocated for
//  a later "Apply" action. Any unsettled remainder becomes an
//  implicit down payment automatically (144 Advances to Suppliers),
//  no separate transaction needed.
//
//  AP ACCOUNT SPLITTING — mirrors the AR splitting on the customer
//  side: 221001 Local vs 221002 Import, resolved per bill's own
//  currency, bucketed the same way.
//
//  WHT — deliberately NONE of this service's concern. Supplier Bill
//  already splits Withholding Tax into its own payable (226002) at
//  bill-posting time — amount_outstanding on the bill is already the
//  correct NET figure. Settling against it here needs zero WHT logic
//  of its own.
//
//  AUDIT H7 FIX (Sept 2026): every settlement line is checked —
//  same partner, posted, matching currency, positive amount — see
//  assertSettleable(). The later allocate() path already did this.
//
//  @layer    Service
//  @depends  SupplierPayment, SupplierPaymentAllocation, SupplierBill,
//            ChequePayable, ChartOfAccount, CompanyActiveCurrency,
//            FinancialInstitutions\BankAccount, FinancialInstitutions\CreditFacility,
//            JournalEntryService, ChequePayableService, FiscalYearService,
//            User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\JournalEntry;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private ChequePayableService $chequePayableService,
        private FacilitySettlementEngine $settlementEngine,
        private CashAvailabilityService $cashAvailability,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SupplierPayment::with(['partner:id,name_en,name_ar', 'payCurrency:id,code', 'cheque', 'allocations'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('payment_code', 'like', "%{$s}%"))
            ->when($filters['payment_type'] ?? null, fn ($q, $t) => $q->where('payment_type', $t))
            ->when($filters['money_type'] ?? null, fn ($q, $t) => $q->where('money_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $settlements = $data['settlements'] ?? [];
            unset($data['settlements']);

            $data['company_id']         = $companyId;
            $data['created_by']         = $actor->id;
            $data['payment_code']       = $this->generatePaymentCode($companyId, $data['payment_date'] ?? null);
            $data['status']             = SupplierPayment::STATUS_POSTED;
            $data['exchange_rate']      = app(FxService::class)->resolveRate(
                $companyId,
                ($data['pay_currency_id'] ?? null) ? (int) ($data['pay_currency_id'] ?? null) : null,
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

            $payment = SupplierPayment::create($data);

            $this->postPaymentTransaction($companyId, $payment, $settlements, $chequeData, $actor);

            AuditLog::record($actor, 'supplier_payment.recorded', $payment, null, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'payCurrency', 'billCurrency', 'cheque', 'allocations.supplierBill', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) payment and immediately re-posts it —
     * mirroring record() exactly, just on an existing row. Only ever
     * callable on a draft (setToDraft() is the only way a posted
     * payment becomes one again).
     */
    public function update(int $companyId, SupplierPayment $payment, array $data, User $actor): SupplierPayment
    {
        if ($payment->status !== SupplierPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft payment can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($companyId, $payment, $data, $actor) {
            $before = $payment->toArray();

            $settlements = $data['settlements'] ?? [];
            unset($data['settlements']);

            $data['exchange_rate']      = app(FxService::class)->resolveRate(
                $companyId,
                ($data['pay_currency_id'] ?? $payment->pay_currency_id) ? (int) ($data['pay_currency_id'] ?? $payment->pay_currency_id) : null,
                substr((string) ($data['payment_date'] ?? $payment->payment_date ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional']  = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['allocated_amount']   = '0';
            $data['unallocated_amount'] = $data['amount_functional'];
            $data['status']             = SupplierPayment::STATUS_POSTED;
            $data['posted_by']          = $actor->id;
            $data['posted_at']          = now();
            $data['updated_by']         = $actor->id;

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment->update($data);

            $this->postPaymentTransaction($companyId, $payment, $settlements, $chequeData, $actor);

            AuditLog::record($actor, 'supplier_payment.reposted', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'payCurrency', 'billCurrency', 'cheque', 'allocations.supplierBill', 'journalEntry.lines']);
        });
    }

    /**
     * The actual posting mechanics — source resolution, AP settlement
     * buckets, the journal entry itself, and cheque creation. Shared
     * between record() and update() so the two can never drift apart.
     */
    private function postPaymentTransaction(int $companyId, SupplierPayment $payment, array $settlements, ?array $chequeData, User $actor): void
    {
        $sourceAccount = $this->resolveSourceAccount($payment);

        // Insufficient-funds guard (see CashAvailabilityService header)
        // — only for money leaving a real bank account or the safe
        // right now. A payable cheque doesn't debit cash until it
        // clears, and a credit-facility draw already checks its own
        // available room inside FacilitySettlementEngine.
        if ($payment->money_type === SupplierPayment::MONEY_CASH_PAYMENT) {
            // Audit C8: compare the EGP value with the EGP ledger balance.
            $this->cashAvailability->assertSafeCanCover($companyId, $sourceAccount, (string) $payment->amount_functional);
        } elseif ($payment->money_type === SupplierPayment::MONEY_OUTGOING_TRANSFER
            && $payment->source_type === SupplierPayment::SOURCE_BANK_ACCOUNT) {
            $bankAccount = BankAccount::findOrFail($payment->source_bank_account_id);
            $this->cashAvailability->assertBankAccountCanCover($companyId, $bankAccount, (string) $payment->amount_functional);
        }

        // Bill Currency → Pay Currency → Functional, chained — same
        // pattern as CustomerPaymentService's collectionRateToFunctional,
        // reversed for the payable side. Both rates default to 1 when
        // not applicable, collapsing cleanly to "no FX" for the vast
        // majority of payments (same currency throughout, rate
        // unchanged since the bill was raised).
        $billToPayRate = $payment->bill_to_pay_exchange_rate ? (string) $payment->bill_to_pay_exchange_rate : '1';
        $payToFunctionalRate = $payment->exchange_rate ? (string) $payment->exchange_rate : '1';
        $paymentRateToFunctional = bcmul($billToPayRate, $payToFunctionalRate, 10);

        $debitBuckets = []; // chart_of_account_id => functional amount (AP clearing)
        $createdAllocationIds = [];
        $fxGainTotal = '0';
        $fxLossTotal = '0';
        $totalCashUsedFunctional = '0'; // valued at TODAY's payment rate

        foreach ($settlements as $line) {
            $bill = SupplierBill::withoutGlobalScopes()->lockForUpdate()->findOrFail($line['supplier_bill_id']);
            $settlementNative = (string) $line['settlement_amount'];

            // Audit H7: the bill must be this supplier's own, posted, in
            // this payment's bill currency, and the amount positive.
            $this->assertSettleable($payment, $bill, $settlementNative);

            // AP clears at the BILL's own booked rate — this is what
            // actually reduces the exact functional-currency liability
            // that was recorded when the bill posted. Unchanged, correct.
            $apClearFromSettlement = bcmul($settlementNative, (string) $bill->exchange_rate, 2);

            if (bccomp($apClearFromSettlement, (string) $bill->amount_outstanding, 2) > 0) {
                $rate = bccomp((string) $bill->exchange_rate, '0', 6) > 0 ? (string) $bill->exchange_rate : '1';
                $outstandingNative = bcdiv((string) $bill->amount_outstanding, $rate, 2);
                throw new \DomainException(
                    "Settlement amount exceeds what's still outstanding on Bill {$bill->bill_code}. ".
                    "Settlement Amount is entered in the bill's own currency — the maximum to fully settle it is {$outstandingNative}."
                );
            }

            // The cash portion's REAL functional value, at today's
            // actual payment rate — what we're genuinely handing over
            // right now, which can differ from what the bill was
            // originally booked at.
            $paymentCashFunctional = bcmul($settlementNative, $paymentRateToFunctional, 2);
            $totalCashUsedFunctional = bcadd($totalCashUsedFunctional, $paymentCashFunctional, 2);

            // Positive = gain (we paid out LESS than the liability was
            // booked at), negative = loss (we paid out MORE) — same
            // sign convention as the customer side, just derived the
            // other way round since this is the payable side.
            $fxDiff = bcsub($apClearFromSettlement, $paymentCashFunctional, 2);
            if (bccomp($fxDiff, '0', 2) > 0) {
                $fxGainTotal = bcadd($fxGainTotal, $fxDiff, 2);
            } elseif (bccomp($fxDiff, '0', 2) < 0) {
                $fxLossTotal = bcadd($fxLossTotal, bcmul($fxDiff, '-1', 2), 2);
            }

            $apAccount = $this->resolveApAccount($companyId, $bill->currency_id);
            $this->addToBucket($debitBuckets, $apAccount->id, $apClearFromSettlement);

            $allocation = SupplierPaymentAllocation::create([
                'company_id'               => $companyId,
                'supplier_payment_id'      => $payment->id,
                'supplier_bill_id'         => $bill->id,
                'allocated_amount'         => $apClearFromSettlement,
                'fx_gain_loss_functional'  => $fxDiff,
                'created_by'               => $actor->id,
            ]);
            $createdAllocationIds[] = $allocation->id;

            $bill->update([
                'amount_paid'         => bcadd((string) $bill->amount_paid, $apClearFromSettlement, 2),
                'amount_outstanding'  => bcsub((string) $bill->amount_outstanding, $apClearFromSettlement, 2),
            ]);

            $payment->increment('allocated_amount', $apClearFromSettlement);
            $payment->decrement('unallocated_amount', $apClearFromSettlement);
        }

        // Whatever's left unsettled is NOT AP — no bill behind it yet.
        // It debits 144 Advances to Suppliers instead, a real asset,
        // whether it's a deliberately-tagged Down Payment or simply an
        // over-payment beyond what Settlement needed. No separate
        // transaction needed — same entry, different debit bucket.
        //
        // Compared against the CASH actually used for settlements
        // (totalCashUsedFunctional, at today's rate) — not the
        // AP-clearing total — since any FX gain/loss is not part of the
        // real cash this payment actually spent.
        $remainder = bcsub((string) $payment->amount_functional, $totalCashUsedFunctional, 2);
        if (bccomp($remainder, '0', 2) < 0) {
            throw new \DomainException('Total settlement amounts (at today\'s payment rate) exceed the amount actually paid.');
        }

        $lines = [];
        foreach ($debitBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Supplier Payment '.$payment->payment_code];
        }

        if (bccomp($remainder, '0', 2) > 0) {
            $advancesAccount = $this->resolveAdvancesToSuppliersAccount($companyId);
            $lines[] = ['chart_of_account_id' => $advancesAccount->id, 'debit' => $remainder, 'credit' => '0', 'description' => 'Supplier Payment '.$payment->payment_code];
        }

        if (bccomp($fxLossTotal, '0', 2) > 0) {
            $fxLossAccount = $this->resolveRealizedFxLossAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxLossAccount->id, 'debit' => $fxLossTotal, 'credit' => '0', 'description' => 'Realized FX Loss — '.$payment->payment_code];
        }

        $lines[] = ['chart_of_account_id' => $sourceAccount->id, 'debit' => '0', 'credit' => (string) $payment->amount_functional, 'description' => 'Supplier Payment '.$payment->payment_code];

        if (bccomp($fxGainTotal, '0', 2) > 0) {
            $fxGainAccount = $this->resolveRealizedFxGainAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxGainAccount->id, 'debit' => '0', 'credit' => $fxGainTotal, 'description' => 'Realized FX Gain — '.$payment->payment_code];
        }

        $journalEntry = $this->journalEntryService->post(
            companyId: $companyId,
            entryDate: $payment->payment_date,
            lines: app(FxService::class)->tagLines($lines, (int) $payment->pay_currency_id, $payment->exchange_rate), // Audit H12/H15
            sourceType: 'supplier_payment',
            sourceId: $payment->id,
            narration: $this->narrationFor($payment),
            actor: $actor,
        );

        $payment->update([
            'journal_entry_id'          => $journalEntry->id,
            'fx_gain_loss_functional'   => bcsub($fxGainTotal, $fxLossTotal, 2),
        ]);

        // Facility Settlement Engine (Bible Rule 34) — the journal
        // entry above already moved the real money into the
        // facility's own COA account (via resolveSourceAccount() /
        // resolveBankOrFacilityAccount()). This only records the
        // sub-ledger drawdown on top of that already-posted entry —
        // never a second journal entry for the same cash.
        if ($payment->source_type === SupplierPayment::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($payment->source_credit_facility_id);

            if (! $facility->bank_account_id) {
                throw new \DomainException('This facility has no linked bank account — cannot record a drawdown against it.');
            }

            // Currency bug fix (2026-09) — facility_limit/outstanding_balance
            // are tracked in the FACILITY's own currency, never functional.
            // amount_functional would silently corrupt the balance by the
            // exchange rate factor on any facility not in the company's
            // functional currency. pay_currency_id is what this payment
            // was actually denominated in when it drew on the facility —
            // it must match the facility's own currency; a mismatch means
            // an FX conversion should have happened first, not a silent
            // wrong-currency post.
            if ((int) $payment->pay_currency_id !== (int) $facility->currency_id) {
                throw new \DomainException(
                    "This payment's currency doesn't match the facility's own currency — ".
                    'convert to the facility currency first, then draw on it.'
                );
            }

            $this->settlementEngine->recordWithdrawal($facility, [
                'bank_account_id' => $facility->bank_account_id,
                'withdrawal_date' => $payment->payment_date,
                'amount'          => $payment->amount,
                'source_type'     => FacilityWithdrawal::SOURCE_SUPPLIER_PAYMENT,
                'source_id'       => $payment->id,
                'journal_entry_id' => $journalEntry->id,
                'description'     => 'Supplier Payment '.$payment->payment_code,
            ], $actor);
        }

        if ($createdAllocationIds) {
            SupplierPaymentAllocation::whereIn('id', $createdAllocationIds)->update(['journal_entry_id' => $journalEntry->id]);
        }

        if ($payment->isCheque()) {
            $this->createChequePayable($payment, $chequeData, $actor);
        }
    }

    /**
     * Reopens a posted payment back to draft — the ONLY way a posted
     * SupplierPayment becomes editable again. Automatically reverses
     * EVERYTHING it did — its own journal entry AND every bill
     * application, including ones added later via "Apply" — then
     * resets the row to a clean draft. Same automatic-cascade
     * philosophy as Customer Collection, confirmed by Mahmoud to
     * apply identically here: never a manual pre-step on a different
     * screen.
     *
     * Blocked if:
     *   - it's already draft or cancelled (nothing to reopen)
     *   - its accounting period is closed
     */
    public function setToDraft(SupplierPayment $payment, User $actor): SupplierPayment
    {
        if ($payment->status !== SupplierPayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted payment can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            $this->reverseEverything($payment, $actor, 'Reopened to draft — '.$payment->payment_code);

            $payment->update([
                'status'             => SupplierPayment::STATUS_DRAFT,
                'journal_entry_id'   => null,
                'posted_by'          => null,
                'posted_at'          => null,
                'allocated_amount'   => '0',
                'unallocated_amount' => $payment->amount_functional,
                'fx_gain_loss_functional' => '0',
                'updated_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'supplier_payment.set_to_draft', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    /**
     * Shared by setToDraft() and cancel(). If the cheque was already
     * Marked as Paid, that real cash journal entry is reversed first
     * (reusing ChequePayableService's own tested reversal), THEN the
     * cheque row itself is removed. No "rejected" block-case exists
     * here — ChequePayable's scope is deliberately just issued/paid
     * (confirmed by Mahmoud), so there's no bounced state to worry
     * about disentangling.
     */
    private function reverseEverything(SupplierPayment $payment, User $actor, string $reversalNarration): void
    {
        $mainJournalEntryId = $payment->journal_entry_id;

        // Facility Settlement Engine — undo the drawdown this payment
        // created, BEFORE reversing the journal entry, so a locked
        // (already-settled) withdrawal blocks the whole reopen/cancel
        // with a clear message rather than leaving the journal
        // reversed but the facility sub-ledger still showing the debt.
        if ($payment->source_type === SupplierPayment::SOURCE_CREDIT_FACILITY) {
            $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_SUPPLIER_PAYMENT)
                ->where('source_id', $payment->id)
                ->first();

            if ($withdrawal) {
                $this->settlementEngine->reverseWithdrawal($withdrawal, $actor);
            }
        }

        foreach ($payment->allocations()->lockForUpdate()->get() as $allocation) {
            $bill   = SupplierBill::lockForUpdate()->findOrFail($allocation->supplier_bill_id);
            $amount = (string) $allocation->allocated_amount;

            if ($allocation->journal_entry_id && $allocation->journal_entry_id !== $mainJournalEntryId) {
                $entry = JournalEntry::findOrFail($allocation->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, $reversalNarration.' from Bill '.$bill->bill_code);
            }

            $bill->update([
                'amount_paid'        => bcsub((string) $bill->amount_paid, $amount, 2),
                'amount_outstanding' => bcadd((string) $bill->amount_outstanding, $amount, 2),
            ]);

            $allocation->delete();
        }

        if ($mainJournalEntryId) {
            $entry = JournalEntry::findOrFail($mainJournalEntryId);
            $this->journalEntryService->reverse($entry, $actor, $reversalNarration);
        }

        if ($payment->isCheque() && $payment->cheque?->isPaid()) {
            $this->chequePayableService->resetPayment($payment->cheque, $actor);
        }

        if ($payment->isCheque() && $payment->cheque) {
            $payment->cheque->delete();
        }
    }

    /**
     * Deletes a payment outright — only on a draft, only reachable via
     * setToDraft() first (which already fully reversed everything real
     * it did). Same "no hard deletes on financial data" reasoning as
     * Customer Collection: by this point there's nothing financial
     * left to protect.
     */
    public function delete(SupplierPayment $payment, User $actor): void
    {
        if ($payment->status !== SupplierPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft payment can be deleted — use "Set to Draft" first.');
        }
        if ($payment->journal_entry_id) {
            throw new \DomainException('This payment still has a journal entry attached — it cannot be deleted.');
        }
        if (! $payment->allocations->isEmpty()) {
            throw new \DomainException('This payment still has bill applications attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            if ($payment->isCheque() && $payment->cheque) {
                $payment->cheque->delete();
            }

            $payment->delete();

            AuditLog::record($actor, 'supplier_payment.deleted', $payment, $before, null);
        });
    }

    public function cancel(SupplierPayment $payment, User $actor): SupplierPayment
    {
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            $this->reverseEverything($payment, $actor, 'Cancelled — '.$payment->payment_code);

            $payment->update([
                'status'             => SupplierPayment::STATUS_CANCELLED,
                'allocated_amount'   => '0',
                'unallocated_amount' => $payment->amount_functional,
                'fx_gain_loss_functional' => '0',
                'updated_by'         => $actor->id,
            ]);
            AuditLog::record($actor, 'supplier_payment.cancelled', $payment, $before, $payment->fresh()->toArray());
            return $payment->fresh();
        });
    }

    // ── Allocate / Unallocate ────────────────────────────────────

    /**
     * Applies previously-unapplied cash (144 Advances to Suppliers)
     * against a real bill. Dr AP / Cr 144 — a genuine account-to-
     * account movement, not a re-labeling. Always creates a NEW
     * allocation row, mirroring Customer Collection's own reasoning.
     */
    public function allocate(SupplierPayment $payment, SupplierBill $bill, string $billCurrencyAmount, User $actor, ?string $applicationDate = null): SupplierPaymentAllocation
    {
        if (! $payment->isPosted()) {
            throw new \DomainException('Only a posted payment can be allocated.');
        }
        if (! $bill->isPosted()) {
            throw new \DomainException('Can only allocate against a posted Supplier Bill.');
        }
        if ($payment->partner_id !== $bill->partner_id) {
            throw new \DomainException('This payment and bill belong to different suppliers.');
        }

        // AUDIT M-12 — mirror of the customer side. The advance sits in
        // 144 at the rate it was PAID at; the bill sits in AP at the rate
        // it was BOOKED at. 144 is relieved at the advance's own rate, AP
        // at the bill's rate, and the difference is realised FX.
        $advanceCurrencyId = $payment->bill_currency_id ?: $payment->pay_currency_id;
        if ($advanceCurrencyId && $bill->currency_id && (int) $advanceCurrencyId !== (int) $bill->currency_id) {
            throw new \DomainException('This advance is held in a different currency from the bill — apply it to a bill in the same currency.');
        }

        $billToPay         = $payment->bill_to_pay_exchange_rate ? (string) $payment->bill_to_pay_exchange_rate : '1';
        $payToFunc         = $payment->exchange_rate ? (string) $payment->exchange_rate : '1';
        $advanceRate       = bcmul($billToPay, $payToFunc, 10);
        $advanceFunctional = bcmul($billCurrencyAmount, $advanceRate, 2);                     // leaves 144
        $apClear           = bcmul($billCurrencyAmount, (string) $bill->exchange_rate, 2);    // leaves AP
        $fxDiff            = bcsub($apClear, $advanceFunctional, 2);                          // + gain / − loss

        if (bccomp($apClear, '0', 2) <= 0 || bccomp($advanceFunctional, '0', 2) <= 0) {
            throw new \DomainException('Allocation amount must be greater than zero.');
        }
        if (bccomp($advanceFunctional, (string) $payment->unallocated_amount, 2) > 0) {
            throw new \DomainException("Allocation exceeds what's still unallocated on this payment ({$payment->unallocated_amount}).");
        }
        if (bccomp($apClear, (string) $bill->amount_outstanding, 2) > 0) {
            throw new \DomainException("Allocation exceeds what's still outstanding on Bill {$bill->bill_code} ({$bill->amount_outstanding}).");
        }

        $entryDate = $applicationDate ? substr($applicationDate, 0, 10) : now()->toDateString();
        foreach (['payment' => $payment->payment_date, 'bill' => $bill->bill_date] as $label => $docDate) {
            if ($docDate && $entryDate < substr((string) $docDate, 0, 10)) {
                throw new \DomainException("The application date cannot be before the {$label} date (".substr((string) $docDate, 0, 10).').');
            }
        }

        return DB::transaction(function () use ($payment, $bill, $advanceFunctional, $apClear, $fxDiff, $entryDate, $actor) {
            $payment = SupplierPayment::lockForUpdate()->findOrFail($payment->id);
            $bill    = SupplierBill::lockForUpdate()->findOrFail($bill->id);

            $advancesAccount = $this->resolveAdvancesToSuppliersAccount($payment->company_id);
            $apAccount = $this->resolveApAccount($payment->company_id, $bill->currency_id);
            $memo = 'Down payment applied — '.$payment->payment_code.' → '.$bill->bill_code;

            $lines = [
                ['chart_of_account_id' => $apAccount->id, 'debit' => $apClear, 'credit' => '0', 'description' => $memo],
                ['chart_of_account_id' => $advancesAccount->id, 'debit' => '0', 'credit' => $advanceFunctional, 'description' => $memo],
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
                sourceType: 'supplier_payment_allocation',
                sourceId: $payment->id,
                narration: "Applied {$payment->payment_code} against Bill {$bill->bill_code} — {$bill->partner->name_en}",
                actor: $actor,
            );

            // allocated_amount is the AP side (what the bill was reduced by).
            $allocation = SupplierPaymentAllocation::create([
                'company_id'              => $payment->company_id,
                'supplier_payment_id'     => $payment->id,
                'supplier_bill_id'        => $bill->id,
                'allocated_amount'        => $apClear,
                'fx_gain_loss_functional' => $fxDiff,
                'journal_entry_id'        => $journalEntry->id,
                'created_by'              => $actor->id,
            ]);

            $payment->update([
                'allocated_amount'        => bcadd((string) $payment->allocated_amount, $advanceFunctional, 2),
                'unallocated_amount'      => bcsub((string) $payment->unallocated_amount, $advanceFunctional, 2),
                'fx_gain_loss_functional' => bcadd((string) $payment->fx_gain_loss_functional, $fxDiff, 2),
            ]);

            $bill->update([
                'amount_paid'         => bcadd((string) $bill->amount_paid, $apClear, 2),
                'amount_outstanding'  => bcsub((string) $bill->amount_outstanding, $apClear, 2),
            ]);

            AuditLog::record($actor, 'supplier_payment.allocated', $payment, null, ['bill' => $bill->bill_code, 'amount' => $apClear, 'advance_released' => $advanceFunctional, 'fx' => $fxDiff, 'date' => $entryDate]);

            return $allocation->fresh(['supplierPayment', 'supplierBill', 'journalEntry.lines']);
        });
    }

    /**
     * Reverses a previously-applied allocation. Same guard as the
     * customer side: a Settlement allocation sharing the payment's own
     * journal entry can't be unapplied on its own — reversing that
     * shared entry here would reverse the whole thing. Those must go
     * through setToDraft() instead.
     */
    public function unallocate(SupplierPaymentAllocation $allocation, User $actor): void
    {
        $payment = SupplierPayment::findOrFail($allocation->supplier_payment_id);

        if ($allocation->journal_entry_id && $allocation->journal_entry_id === $payment->journal_entry_id) {
            throw new \DomainException(
                'This application was part of the payment\'s original posting, not a separate one — use "Set to Draft" on the payment itself to undo it.'
            );
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — this application cannot be reversed.');
        }

        DB::transaction(function () use ($allocation, $actor) {
            $payment = SupplierPayment::lockForUpdate()->findOrFail($allocation->supplier_payment_id);
            $bill    = SupplierBill::lockForUpdate()->findOrFail($allocation->supplier_bill_id);
            $amount  = (string) $allocation->allocated_amount;  // AP side
            $fx      = (string) $allocation->fx_gain_loss_functional;
            $advance = bcsub($amount, $fx, 2);                  // AUDIT M-12: 144 side = AP side − FX

            if ($allocation->journal_entry_id) {
                $entry = JournalEntry::findOrFail($allocation->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Unallocated — '.$payment->payment_code.' from Bill '.$bill->bill_code);
            }

            $payment->update([
                'allocated_amount'        => bcsub((string) $payment->allocated_amount, $advance, 2),
                'unallocated_amount'      => bcadd((string) $payment->unallocated_amount, $advance, 2),
                'fx_gain_loss_functional' => bcsub((string) $payment->fx_gain_loss_functional, $fx, 2),
            ]);

            $bill->update([
                'amount_paid'         => bcsub((string) $bill->amount_paid, $amount, 2),
                'amount_outstanding'  => bcadd((string) $bill->amount_outstanding, $amount, 2),
            ]);

            AuditLog::record($actor, 'supplier_payment.unallocated', $payment, ['bill' => $bill->bill_code, 'amount' => $amount], null);

            $allocation->delete();
        });
    }

    /**
     * Payable-side mirror of CustomerPaymentService::releaseOriginalAllocation()
     * — built this way from the start so Supplier Bills never hit the
     * bug Customer Invoices had (unlinking wiping the cheque's Drawee
     * Bank / Due Date / Cheque Number / Receiving Safe). Unlinks an
     * allocation that was part of the payment's ORIGINAL posting
     * without reversing the payment itself: reverses just that entry
     * and re-posts the same money as unapplied (144 Advances to
     * Suppliers). The payment stays POSTED, its cheque (and the
     * cheque's own status — Issued, whatever it was) is never touched.
     *
     * Only safe when this original entry settled ONLY this one bill —
     * if it combined several bills into one shared AP credit, reversing
     * it would silently un-settle the others too. That case still needs
     * the manual "reopen the payment" step (Set to Draft).
     */
    public function releaseOriginalAllocation(SupplierPaymentAllocation $allocation, User $actor): void
    {
        $payment = SupplierPayment::findOrFail($allocation->supplier_payment_id);

        if (! $allocation->journal_entry_id || $allocation->journal_entry_id !== $payment->journal_entry_id) {
            throw new \DomainException('This application is not part of the payment\'s original posting — use unallocate() instead.');
        }

        $touchesOtherBills = SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)
            ->where('id', '!=', $allocation->id)
            ->where('journal_entry_id', $payment->journal_entry_id)
            ->exists();

        if ($touchesOtherBills) {
            throw new \DomainException(
                "This payment's original posting also settled other bills — reopen the payment itself (Set to Draft) first, then re-save it without this bill."
            );
        }

        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — this application cannot be released.');
        }

        DB::transaction(function () use ($allocation, $actor) {
            $payment = SupplierPayment::lockForUpdate()->findOrFail($allocation->supplier_payment_id);
            $bill    = SupplierBill::lockForUpdate()->findOrFail($allocation->supplier_bill_id);
            $amount  = (string) $allocation->allocated_amount;

            $originalEntry = JournalEntry::findOrFail($allocation->journal_entry_id);
            $this->journalEntryService->reverse(
                $originalEntry,
                $actor,
                'Released — '.$payment->payment_code.' unlinked from Bill '.$bill->bill_code
            );

            // Re-post the exact same money, still sitting wherever it
            // was (Notes Payable / Bank / Safe) — just no longer tied
            // to this bill's AP.
            $sourceAccount   = $this->resolveSourceAccount($payment);
            $advancesAccount = $this->resolveAdvancesToSuppliersAccount($payment->company_id);

            $newEntry = $this->journalEntryService->post(
                companyId: $payment->company_id,
                entryDate: $payment->payment_date,
                lines: [
                    ['chart_of_account_id' => $advancesAccount->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Held as unapplied — '.$payment->payment_code],
                    ['chart_of_account_id' => $sourceAccount->id,   'debit' => '0', 'credit' => $amount, 'description' => 'Held as unapplied — '.$payment->payment_code,
                        'currency_id' => $payment->pay_currency_id, 'exchange_rate' => $payment->exchange_rate], // Audit H12/H15
                ],
                sourceType: 'supplier_payment_unapplied',
                sourceId: $payment->id,
                narration: 'Released from Bill '.$bill->bill_code.' — '.$payment->payment_code.' held as unapplied',
                actor: $actor,
            );

            $payment->update([
                'journal_entry_id'   => $newEntry->id,
                'allocated_amount'   => bcsub((string) $payment->allocated_amount, $amount, 2),
                'unallocated_amount' => bcadd((string) $payment->unallocated_amount, $amount, 2),
            ]);

            $bill->update([
                'amount_paid'         => bcsub((string) $bill->amount_paid, $amount, 2),
                'amount_outstanding'  => bcadd((string) $bill->amount_outstanding, $amount, 2),
            ]);

            AuditLog::record($actor, 'supplier_payment.original_allocation_released', $payment, ['bill' => $bill->bill_code, 'amount' => $amount], null);

            $allocation->delete();
        });
    }

    // ── Private — source resolution ─────────────────────────────

    private function resolveSourceAccount(SupplierPayment $payment): ChartOfAccount
    {
        return match ($payment->money_type) {
            SupplierPayment::MONEY_OUTGOING_TRANSFER => $this->resolveBankOrFacilityAccount($payment),
            SupplierPayment::MONEY_CASH_PAYMENT      => $this->resolveSafeAccount($payment),
            SupplierPayment::MONEY_PAYABLE_CHEQUE    => $this->resolveNotesPayableAccount($payment),
            default => throw new \DomainException("Unknown money type: {$payment->money_type}"),
        };
    }

    private function resolveBankOrFacilityAccount(SupplierPayment $payment): ChartOfAccount
    {
        if ($payment->source_type === SupplierPayment::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::where('id', $payment->source_credit_facility_id)
                ->where('company_id', $payment->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $payment->source_bank_account_id)
            ->where('company_id', $payment->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The selected bank account does not belong to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveSafeAccount(SupplierPayment $payment): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($payment->company_id)
            ->where('id', $payment->safe_account_id)->first();

        return $account ?? throw new \DomainException('The selected Safe account does not belong to this company.');
    }

    private function resolveNotesPayableAccount(SupplierPayment $payment): ChartOfAccount
    {
        return ChartOfAccount::findByCode($payment->company_id, '222001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 222001 (Notes Payable — Suppliers) — check the seeder.');
    }

    /** Audit H7 — a settlement line may only touch this supplier's own posted bill, in the right currency. */
    private function assertSettleable(SupplierPayment $payment, SupplierBill $bill, string $settlement): void
    {
        if ((int) $bill->company_id !== (int) $payment->company_id || (int) $bill->partner_id !== (int) $payment->partner_id) {
            throw new \DomainException("Bill {$bill->bill_code} belongs to a different supplier — a payment can only settle its own supplier's bills.");
        }
        if (! $bill->isPosted()) {
            throw new \DomainException("Bill {$bill->bill_code} is not posted — only posted bills can be paid.");
        }
        if ($payment->bill_currency_id && (int) $bill->currency_id !== (int) $payment->bill_currency_id) {
            throw new \DomainException("Bill {$bill->bill_code} is in a different currency from the one this payment is settling.");
        }
        if (! is_numeric($settlement) || bccomp($settlement, '0', 2) <= 0) {
            throw new \DomainException("Settlement on Bill {$bill->bill_code} must be a positive amount.");
        }
    }

    private function resolveApAccount(int $companyId, int $currencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
    }

    /** 144 Advances to Suppliers — money paid with no bill behind it yet. */
    private function resolveAdvancesToSuppliersAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '144')
            ?? throw new \DomainException('Chart of Accounts is missing system account 144 (Advances to Suppliers) — check the seeder.');
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

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    private function narrationFor(SupplierPayment $payment): string
    {
        $label = $payment->isDownPayment() ? 'Down Payment' : 'Payment';
        return "Supplier {$label} {$payment->payment_code} — {$payment->partner->name_en}";
    }

    private function createChequePayable(SupplierPayment $payment, ?array $chequeData, User $actor): ChequePayable
    {
        if (! $chequeData) {
            throw new \DomainException('Cheque details are required for a Payable Cheque payment.');
        }

        return ChequePayable::create([
            'company_id'          => $payment->company_id,
            'supplier_payment_id' => $payment->id,
            'partner_id'          => $payment->partner_id,
            'cheque_amount'       => $payment->amount,
            // Audit C8: remember the cheque's currency and the EGP value booked, so clearing it can value any FX difference.
            'currency_id'         => $payment->pay_currency_id,
            'exchange_rate'       => $payment->exchange_rate ?: 1,
            'amount_functional'   => $payment->amount_functional,
            'cheque_number'       => $chequeData['cheque_number'],
            'due_date'            => $chequeData['due_date'],
            'status'              => ChequePayable::STATUS_ISSUED,
            'created_by'          => $actor->id,
        ]);
    }

    private function generatePaymentCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\SupplierPayment::class, 'payment_code', 'SP', $date);
    }
}
