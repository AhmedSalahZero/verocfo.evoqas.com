<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ChequeReceivableService
//
//  Customer Collection redesign (July 2026) — see
//  VeroCFO_Customer_Collection_Design_v1_0.md Section 6, the
//  authoritative reference. The cheque's own lifecycle from
//  'received' onward — receiving it already settled the customer's
//  AR (CustomerPaymentService::record(), Dr 134 / Cr AR), so
//  everything here concerns only the physical instrument's journey.
//
//    sendForCollection() — NO POSTING. Records which of our own
//      accounts (Current or Overdraft) the cheque is being sent to
//      clear into. Confirmed by Mahmoud: a pure status change.
//    collect()  — Dr [that destination] / Cr 134 Notes Receivable.
//      This is where real cash (or OD paydown) actually lands.
//    reject()   — Dr 133 AR / Cr 134 Notes Receivable instead. AR
//      simply reopens. Bank charges on the bounce are explicitly
//      deferred to a future Expense Payment screen.
//
//  AUDIT C8 FIX (Sept 2026) — foreign-currency cheques. collect()
//  and reject() used to post cheque_amount (the cheque's OWN currency
//  — e.g. USD 1,000) straight into the EGP ledger, while 134 had been
//  debited at the EGP value (e.g. 50,000). The difference sat in
//  Notes Receivable forever. Now:
//    collect() — Cr 134 at the value it was BOOKED at
//                (ChequeReceivable::bookedFunctional()), Dr the bank at
//                the cheque amount × the rate on the collection date
//                (FxService), difference to 431 / 571.
//    reject()  — Dr AR / Cr 134, both at the booked value (the
//                customer owes exactly what was cleared off AR).
//
//  AUDIT C7 (Sept 2026) — cheques created from Opening Balances have
//  no parent collection. On rejection they reopen the customer's AR
//  account for the cheque's currency (133001 / 133002).
//
//  @layer    Service
//  @depends  ChequeReceivable, CustomerPayment, ChartOfAccount, FxService,
//            CompanyActiveCurrency, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, JournalEntryService,
//            User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequeReceivable;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\JournalEntry;
use App\Models\OtherCollection;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Support\Facades\DB;

class ChequeReceivableService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private FxService $fx,
    ) {
    }

    // ── Send for Collection — status change only, no posting ────────

    public function sendForCollection(ChequeReceivable $cheque, array $data, User $actor): ChequeReceivable
    {
        if (! $cheque->isReceived()) {
            throw new \DomainException('Only a cheque still sitting as "received" can be sent for collection.');
        }

        $destinationType = $data['collection_destination_type'];
        if (! in_array($destinationType, ['bank_account', 'credit_facility'], true)) {
            throw new \DomainException('Collection destination must be a bank account or a credit facility.');
        }

        // Confirmed with Mahmoud: this date matters far beyond a status
        // timestamp — it's the anchor date OD Against Commercial Paper
        // will use to price its lending tiers (days-to-due FROM this
        // date, not from today). Previously hardcoded to now(), which
        // silently lost the real deposit date whenever someone recorded
        // this a day or more after it actually happened.
        if (empty($data['sent_for_collection_at'])) {
            throw new \DomainException('Send to Collection Date is required.');
        }
        if ($data['sent_for_collection_at'] > now()->toDateString()) {
            throw new \DomainException('Send to Collection Date cannot be in the future.');
        }

        return DB::transaction(function () use ($cheque, $data, $destinationType, $actor) {
            $before = $cheque->toArray();

            $cheque->update([
                'status'                                       => ChequeReceivable::STATUS_SENT_FOR_COLLECTION,
                'collection_destination_type'                  => $destinationType,
                'collection_destination_bank_account_id'       => $destinationType === 'bank_account' ? $data['destination_id'] : null,
                'collection_destination_credit_facility_id'    => $destinationType === 'credit_facility' ? $data['destination_id'] : null,
                'sent_for_collection_at'                       => $data['sent_for_collection_at'],
            ]);

            // Fix: audit_log.action is capped at 30 chars —
            // 'cheque_receivable.sent_for_collection' was 37 chars and
            // failed on every real attempt to send a cheque. Shortened
            // prefix only; 'collected'/'rejected' below were already
            // under the limit and are left as-is.
            AuditLog::record($actor, 'cheque.sent_for_collection', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh();
        });
    }

    /**
     * Undoes "Send for Collection" — for exactly the mistake case
     * (wrong account picked, wrong date, or it shouldn't have been sent
     * yet). Safe as a plain status revert, not a "reversal" in the
     * accounting sense: sendForCollection() never posts a journal entry
     * (confirmed above — nothing real has happened to the cheque yet,
     * by design, same as everywhere else in this app), so there's
     * nothing to reverse, only a status and a few fields to clear.
     * Blocked once the cheque has actually collected or been rejected —
     * at that point something real DID happen, and undoing it means
     * genuinely reversing collect()/reject(), a different action
     * entirely (not built here — matches this app's existing pattern
     * of never quietly repurposing one action to also do another).
     */
    public function returnToSafe(ChequeReceivable $cheque, User $actor): ChequeReceivable
    {
        if (! $cheque->isSentForCollection()) {
            throw new \DomainException('Only a cheque currently "Sent for Collection" can be returned to the safe.');
        }

        return DB::transaction(function () use ($cheque, $actor) {
            $before = $cheque->toArray();

            $cheque->update([
                'status'                                       => ChequeReceivable::STATUS_RECEIVED,
                'collection_destination_type'                  => null,
                'collection_destination_bank_account_id'       => null,
                'collection_destination_credit_facility_id'    => null,
                'sent_for_collection_at'                        => null,
            ]);

            AuditLog::record($actor, 'cheque.returned_to_safe', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh();
        });
    }

    // ── Collect — real cash (or OD paydown) lands ───────────────────

    public function collect(ChequeReceivable $cheque, string $collectionDate, User $actor): ChequeReceivable
    {
        if (! $cheque->isSentForCollection()) {
            throw new \DomainException('Only a cheque sent for collection can be marked collected.');
        }

        // Real-world rule (confirmed with Mahmoud): a cheque cannot
        // legally clear before its own due date — a bank will not
        // honor it early. Same reasoning as backdating rules elsewhere
        // in this app: the system should refuse what reality refuses.
        if ($collectionDate < $cheque->due_date->format('Y-m-d')) {
            throw new \DomainException(
                "This cheque cannot be collected before its due date ({$cheque->due_date->format('d-m-Y')})."
            );
        }

        return DB::transaction(function () use ($cheque, $collectionDate, $actor) {
            $before = $cheque->toArray();

            $destinationAccount = $this->resolveCollectionDestination($cheque);
            $notesReceivableAccount = $this->resolveNotesReceivableAccount($cheque->company_id);

            // C8: 134 is cleared at what it was booked at; the bank is
            // debited at today's value of the cheque; any difference is
            // realised FX.
            $booked  = $cheque->bookedFunctional();
            $rate    = $this->fx->rateOn($cheque->company_id, $cheque->currencyIdResolved(), $collectionDate);
            $settled = $this->fx->toFunctional($cheque->cheque_amount, $rate);

            $lines = [
                ['chart_of_account_id' => $destinationAccount->id, 'debit' => $settled, 'credit' => '0', 'description' => 'Cheque collected — '.$cheque->cheque_number],
                ['chart_of_account_id' => $notesReceivableAccount->id, 'debit' => '0', 'credit' => $booked, 'description' => 'Cheque collected — '.$cheque->cheque_number],
            ];
            if ($fxLine = $this->fx->fxDifferenceLine($cheque->company_id, $booked, $settled, 'debit', 'cheque '.$cheque->cheque_number)) {
                $lines[] = $fxLine;
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $cheque->company_id,
                entryDate: $collectionDate,
                lines: $lines,
                sourceType: 'cheque_receivable',
                sourceId: $cheque->id,
                narration: "Cheque {$cheque->cheque_number} collected — {$cheque->partner->name_en}",
                actor: $actor,
            );

            $cheque->update([
                'status'                    => ChequeReceivable::STATUS_COLLECTED,
                'collected_journal_entry_id'=> $journalEntry->id,
                // Fix: was hardcoded to now() while the journal entry
                // itself was correctly dated to the real $collectionDate
                // the user entered — the two could silently disagree
                // (e.g. recording a collection a day late). Now consistent.
                'collected_at'              => $collectionDate,
            ]);

            AuditLog::record($actor, 'cheque_receivable.collected', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh(['collectedJournalEntry.lines']);
        });
    }

    /**
     * Undoes a mistaken "Collect" — reverses the real journal entry it
     * posted (a genuine reversal, never a delete — same rule as
     * everywhere else in this app) and puts the cheque back to "Sent
     * for Collection" (the state it was in right before Collect, not
     * all the way back to "Received" — the bank destination and send
     * date chosen earlier are still valid and shouldn't have to be
     * re-entered). Blocked if the collection's own accounting period
     * is closed.
     */
    public function undoCollect(ChequeReceivable $cheque, User $actor): ChequeReceivable
    {
        if (! $cheque->isCollected()) {
            throw new \DomainException('Only a collected cheque can be reset.');
        }
        if ($this->fiscalYearService->isPeriodClosed($cheque->company_id, (string) $cheque->collected_at)) {
            throw new \DomainException('This cheque\'s accounting period is closed — its collection cannot be undone.');
        }

        return DB::transaction(function () use ($cheque, $actor) {
            $before = $cheque->toArray();

            if ($cheque->collected_journal_entry_id) {
                $entry = JournalEntry::findOrFail($cheque->collected_journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Collection undone — '.$cheque->cheque_number);
            }

            $cheque->update([
                'status'                     => ChequeReceivable::STATUS_SENT_FOR_COLLECTION,
                'collected_journal_entry_id' => null,
                'collected_at'               => null,
            ]);

            AuditLog::record($actor, 'cheque.collect_undone', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh();
        });
    }

    // ── Reject — the original credit side reopens ────────────────────
    //
    //  A Customer Collection cheque bouncing reopens AR (the customer
    //  still owes the money). An Other Collection cheque bouncing
    //  reopens whatever THAT collection originally credited — a
    //  Shareholder's cheque bouncing reopens 230, an Employee's
    //  reopens 140, and so on — never AR, since no AR was ever
    //  involved. resolveReopenAccount() below branches on source().

    public function reject(ChequeReceivable $cheque, string $rejectionDate, User $actor): ChequeReceivable
    {
        if (! $cheque->isSentForCollection() && ! $cheque->isReceived()) {
            throw new \DomainException('This cheque has already been collected or rejected.');
        }

        return DB::transaction(function () use ($cheque, $rejectionDate, $actor) {
            $before = $cheque->toArray();

            $reopenAccount = $this->resolveReopenAccount($cheque);
            $notesReceivableAccount = $this->resolveNotesReceivableAccount($cheque->company_id);

            // C8: both sides at the booked functional value — the
            // balance simply moves back from 134 to where it came from.
            $booked = $cheque->bookedFunctional();

            $journalEntry = $this->journalEntryService->post(
                companyId: $cheque->company_id,
                entryDate: $rejectionDate,
                lines: [
                    ['chart_of_account_id' => $reopenAccount->id, 'debit' => $booked, 'credit' => '0', 'description' => 'Cheque rejected — '.$cheque->cheque_number],
                    ['chart_of_account_id' => $notesReceivableAccount->id, 'debit' => '0', 'credit' => $booked, 'description' => 'Cheque rejected — '.$cheque->cheque_number],
                ],
                sourceType: 'cheque_receivable',
                sourceId: $cheque->id,
                narration: "Cheque {$cheque->cheque_number} rejected — {$cheque->partner->name_en}. Original balance reopened; bank charges (if any) recorded separately via Expense Payment.",
                actor: $actor,
            );

            $cheque->update([
                'status'                    => ChequeReceivable::STATUS_REJECTED,
                'rejected_journal_entry_id' => $journalEntry->id,
                // Fix: same date bug as collect() had — was hardcoded to
                // now() while the journal entry was correctly dated to
                // the real $rejectionDate the user entered.
                'rejected_at'               => $rejectionDate,
            ]);

            AuditLog::record($actor, 'cheque_receivable.rejected', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh(['rejectedJournalEntry.lines']);
        });
    }

    // ── Private ───────────────────────────────────────────────────

    private function resolveCollectionDestination(ChequeReceivable $cheque): ChartOfAccount
    {
        if ($cheque->collection_destination_type === 'credit_facility') {
            $facility = CreditFacility::where('id', $cheque->collection_destination_credit_facility_id)
                ->where('company_id', $cheque->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $cheque->collection_destination_bank_account_id)
            ->where('company_id', $cheque->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The selected collection destination does not belong to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveNotesReceivableAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '134001')
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
     * Whatever account should reopen when this cheque bounces — AR for
     * a Customer Collection cheque (existing behavior, unchanged), or
     * the exact same Chart of Accounts account the parent Other
     * Collection originally credited (see OtherCollection::
     * credit_account_id) for an Other Collection cheque.
     */
    private function resolveReopenAccount(ChequeReceivable $cheque): ChartOfAccount
    {
        // C7: an opening-balance cheque came from a customer's AR —
        // reopen the AR account for the cheque's own currency.
        if ($cheque->isFromOpeningBalance()) {
            return $this->resolveArAccount(
                $cheque->company_id,
                $cheque->currencyIdResolved() ?? (int) $this->fx->functionalCurrencyId($cheque->company_id)
            );
        }

        if ($cheque->isFromOtherCollection()) {
            $otherCollection = $cheque->otherCollection;

            return ChartOfAccount::withoutGlobalScopes()->forCompany($otherCollection->company_id)
                ->where('id', $otherCollection->credit_account_id)->first()
                ?? throw new \DomainException('The original Other Collection credit account could not be found — cannot reopen it.');
        }

        return $this->resolveArAccount($cheque->company_id, $cheque->customerPayment->invoice_currency_id ?? $cheque->customerPayment->receive_currency_id);
    }
}
