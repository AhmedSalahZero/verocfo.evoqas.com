<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherCollectionService
//
//  Other Collection — money received from a Shareholder, Employee,
//  Sister Company, Supplier (refund), or Other partner. See
//  VeroCFO_Other_Collection_Design_v1_0.md, the authoritative
//  reference for every decision below. Deliberately mirrors
//  CustomerPaymentService's shape (record/update/postTransaction/
//  setToDraft/delete/cancel, same reversible-draft lifecycle) since
//  this is the same MONEY-IN posture — the one real difference is
//  there is no settlement/allocation step, because there are no
//  invoices to match against.
//
//  ONE ATOMIC ACTION — record(). Posts immediately, no draft-then-
//  post two-step, same posture as Customer Collection.
//
//  DESTINATION RESOLUTION — identical branching to
//  CustomerPaymentService::resolveDestinationAccount():
//    bank_deposit / incoming_transfer → a BankAccount or a
//      CreditFacility (depositing into an Overdraft pays it down
//      directly) — when it's a CreditFacility, this also records a
//      real settlement against it via FacilitySettlementEngine
//      (Bible Rule 34), the same way CustomerPaymentService already
//      does. 2026-09 fix — previously the GL entry posted correctly
//      but the facility's own sub-ledger never learned about it.
//    cash_in_safe → safe_account_id directly (a 131xxx Chart of
//      Accounts sub-account).
//    cheque → always 134001 Notes Receivable. The cheque's own onward
//      journey lives in ChequeReceivableService, shared with Customer
//      Collection cheques (see migration 000173) — this table is not
//      duplicated for Other Collection.
//
//  CREDIT SIDE — the one place this genuinely differs from Customer/
//  Supplier Collection: there is no single system-resolved credit
//  account (no AR, no AP). The user picks credit_account_id directly
//  from the Chart of Accounts (the frontend suggests a default based
//  on Partner Type — see OtherCollection::DEFAULT_CREDIT_ACCOUNT_CODE
//  — but never enforces it). The posted entry is always exactly two
//  lines: Dr [destination] / Cr credit_account_id, in full.
//
//  @layer    Service
//  @depends  OtherCollection, ChequeReceivable, ChartOfAccount,
//            FinancialInstitutions\BankAccount, FinancialInstitutions\CreditFacility,
//            FinancialInstitutions\FacilitySettlement,
//            JournalEntryService, FiscalYearService, ChequeReceivableService,
//            FacilitySettlementEngine, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequeReceivable;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Models\JournalEntry;
use App\Models\OtherCollection;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OtherCollectionService
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
        return OtherCollection::with(['partner:id,name_en,name_ar', 'creditAccount:id,code,name_en', 'currency:id,code', 'cheque', 'cheques'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('collection_code', 'like', "%{$s}%"))
            ->when($filters['partner_type'] ?? null, fn ($q, $t) => $q->where('partner_type', $t))
            ->when($filters['money_type'] ?? null, fn ($q, $t) => $q->where('money_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): OtherCollection
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['company_id']        = $companyId;
            $data['created_by']        = $actor->id;
            $data['collection_code']   = $this->generateCollectionCode($companyId, $data['collection_date'] ?? null);
            $data['status']            = OtherCollection::STATUS_POSTED;
            $data['exchange_rate']     = app(FxService::class)->resolveRate(
                $companyId,
                ($data['currency_id'] ?? null) ? (int) ($data['currency_id'] ?? null) : null,
                substr((string) ($data['collection_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional'] = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['posted_by']         = $actor->id;
            $data['posted_at']         = now();

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $collection = OtherCollection::create($data);

            $this->postCollectionTransaction($collection, $chequeData, $actor);

            AuditLog::record($actor, 'other_collection.recorded', $collection, null, $collection->fresh()->toArray());

            return $collection->fresh(['partner', 'currency', 'creditAccount', 'cheque', 'cheques', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) collection and immediately re-posts it —
     * mirrors record() exactly, just on an existing row. Only ever
     * callable on a draft (setToDraft() is the only way a posted
     * collection becomes one again).
     */
    public function update(OtherCollection $collection, array $data, User $actor): OtherCollection
    {
        if ($collection->status !== OtherCollection::STATUS_DRAFT) {
            throw new \DomainException('Only a draft collection can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($collection, $data, $actor) {
            $before = $collection->toArray();

            $data['exchange_rate']     = app(FxService::class)->resolveRate(
                $collection->company_id,
                ($data['currency_id'] ?? $collection->currency_id) ? (int) ($data['currency_id'] ?? $collection->currency_id) : null,
                substr((string) ($data['collection_date'] ?? $collection->collection_date ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional'] = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['status']            = OtherCollection::STATUS_POSTED;
            $data['posted_by']         = $actor->id;
            $data['posted_at']         = now();
            $data['updated_by']        = $actor->id;

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $collection->update($data);

            $this->postCollectionTransaction($collection, $chequeData, $actor);

            AuditLog::record($actor, 'other_collection.reposted', $collection, $before, $collection->fresh()->toArray());

            return $collection->fresh(['partner', 'currency', 'creditAccount', 'cheque', 'cheques', 'journalEntry.lines']);
        });
    }

    /**
     * The actual posting mechanics — shared between record() and
     * update() so the two can never quietly drift apart. Always
     * exactly two lines: Dr [destination] / Cr credit_account_id.
     */
    private function postCollectionTransaction(OtherCollection $collection, ?array $chequeData, User $actor): void
    {
        $destinationAccount = $this->resolveDestinationAccount($collection);
        $creditAccount = ChartOfAccount::withoutGlobalScopes()->forCompany($collection->company_id)
            ->where('id', $collection->credit_account_id)->first();

        if (! $creditAccount) {
            throw new \DomainException('Select the Chart of Accounts account this collection should credit.');
        }

        $amount = (string) $collection->amount_functional;

        $journalEntry = $this->journalEntryService->post(
            companyId: $collection->company_id,
            entryDate: $collection->collection_date,
            // Audit H12/H15: stamp currency + rate so a foreign bank/safe keeps its exact foreign balance.
            lines: app(FxService::class)->tagLines([
                ['chart_of_account_id' => $destinationAccount->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Other Collection '.$collection->collection_code],
                ['chart_of_account_id' => $creditAccount->id, 'debit' => '0', 'credit' => $amount, 'description' => 'Other Collection '.$collection->collection_code],
            ], $collection->currency_id ? (int) $collection->currency_id : null, $collection->exchange_rate),
            sourceType: 'other_collection',
            sourceId: $collection->id,
            narration: $this->narrationFor($collection),
            actor: $actor,
        );

        $collection->update(['journal_entry_id' => $journalEntry->id]);

        // Facility Settlement Engine (Bible Rule 34) — 2026-09 fix.
        // The journal entry above already paid the facility down for
        // real (it's one of the debit lines built above). This only
        // decides which charge/withdrawal that money actually clears,
        // in Rule 34.2 priority order — never a second journal entry.
        // Mirrors CustomerPaymentService::postPaymentTransaction()
        // exactly.
        if ($collection->destination_type === OtherCollection::DESTINATION_CREDIT_FACILITY) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($collection->destination_credit_facility_id);

            if (! $facility->bank_account_id) {
                throw new \DomainException('This facility has no linked bank account — cannot record a settlement against it.');
            }

            // The facility's own outstanding_balance is tracked in its
            // own currency; a mismatch means an FX conversion should
            // have happened first, not a silent wrong-currency post —
            // same rule as Customer Payment/Supplier Payment.
            if ((int) $collection->currency_id !== (int) $facility->currency_id) {
                throw new \DomainException(
                    "This collection's currency doesn't match the facility's own currency — ".
                    'convert to the facility currency first, then deposit into it.'
                );
            }

            $this->settlementEngine->recordSettlement($facility, [
                'bank_account_id'  => $facility->bank_account_id,
                'settlement_date'  => $collection->collection_date,
                'amount'           => $collection->amount,
                'source_type'      => FacilitySettlement::SOURCE_OTHER_COLLECTION,
                'source_id'        => $collection->id,
                'journal_entry_id' => $journalEntry->id,
            ], $actor);
        }

        if ($collection->isCheque()) {
            $this->createChequeReceivable($collection, $chequeData, $actor);
        }
    }

    /**
     * Reopens a posted collection back to draft — the ONLY way a
     * posted OtherCollection becomes editable again. Automatically
     * reverses its journal entry and, if a cheque was ever collected,
     * that too — same automatic-cascade philosophy as
     * CustomerPaymentService::setToDraft(), confirmed with Mahmoud to
     * apply everywhere "Set to Draft" exists in the app, not just
     * Customer Collection.
     */
    public function setToDraft(OtherCollection $collection, User $actor): OtherCollection
    {
        if ($collection->status !== OtherCollection::STATUS_POSTED) {
            throw new \DomainException('Only a posted collection can be set back to draft.');
        }
        $this->assertReversible($collection);
        if ($this->fiscalYearService->isPeriodClosed($collection->company_id, (string) $collection->collection_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($collection, $actor) {
            $before = $collection->toArray();

            // Facility Settlement Engine — undo the settlement this
            // collection created, BEFORE reversing the journal entry,
            // same ordering as CustomerPaymentService::reverseEverything().
            // If any of what it paid off has itself since been
            // reversed or resettled, reverseSettlement() throws a
            // clear domain exception rather than leaving things
            // half-undone.
            if ($collection->destination_type === OtherCollection::DESTINATION_CREDIT_FACILITY) {
                $settlement = FacilitySettlement::where('source_type', FacilitySettlement::SOURCE_OTHER_COLLECTION)
                    ->where('source_id', $collection->id)
                    ->posted()
                    ->first();

                if ($settlement) {
                    $this->settlementEngine->reverseSettlement($settlement, $actor);
                }
            }

            if ($collection->journal_entry_id) {
                $entry = JournalEntry::findOrFail($collection->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$collection->collection_code);
            }

            if ($collection->isCheque()) {
                foreach ($collection->cheques as $c) {
                    if ($c->isCollected()) {
                        $this->chequeReceivableService->undoCollect($c, $actor);
                    }
                    $c->delete();
                }
            }

            $collection->update([
                'status'           => OtherCollection::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'other_collection.set_to_draft', $collection, $before, $collection->fresh()->toArray());

            return $collection->fresh();
        });
    }

    /**
     * Same guard as CustomerPaymentService::assertReversible() — a
     * rejected cheque already reopened its credit account through
     * reject()'s own journal entry, so unwinding the original
     * collection at the same time creates a genuine ambiguity. Needs
     * a manual decision, not an automatic reversal.
     */
    private function assertReversible(OtherCollection $collection): void
    {
        if ($collection->isCheque() && $collection->cheques->contains(fn ($c) => $c->isRejected())) {
            throw new \DomainException('This cheque was rejected — reopening the collection it came from needs a manual decision, not an automatic reversal.');
        }
    }

    /**
     * Deletes a collection outright — only ever on a draft, and the
     * only way a collection becomes a draft is via setToDraft(), which
     * has already reversed everything real it did. Same "harmless
     * empty shell" reasoning as CustomerPaymentService::delete().
     */
    public function delete(OtherCollection $collection, User $actor): void
    {
        if ($collection->status !== OtherCollection::STATUS_DRAFT) {
            throw new \DomainException('Only a draft collection can be deleted — use "Set to Draft" first.');
        }
        if ($collection->journal_entry_id) {
            throw new \DomainException('This collection still has a journal entry attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($collection, $actor) {
            $before = $collection->toArray();

            if ($collection->isCheque()) {
                $collection->cheques->each->delete();
            }

            $collection->delete();

            AuditLog::record($actor, 'other_collection.deleted', $collection, $before, null);
        });
    }

    /**
     * Cancels a posted collection outright — a dead end, unlike
     * setToDraft() which leaves it editable. Same reversal mechanics
     * (journal entry + any collected cheque), but the row ends at
     * STATUS_CANCELLED rather than STATUS_DRAFT.
     */
    public function cancel(OtherCollection $collection, User $actor): OtherCollection
    {
        if ($collection->status !== OtherCollection::STATUS_POSTED) {
            throw new \DomainException('Only a posted collection can be cancelled.');
        }
        $this->assertReversible($collection);
        if ($this->fiscalYearService->isPeriodClosed($collection->company_id, (string) $collection->collection_date)) {
            throw new \DomainException('This collection\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($collection, $actor) {
            $before = $collection->toArray();

            // Facility Settlement Engine — same reversal, same
            // ordering, as setToDraft() above.
            if ($collection->destination_type === OtherCollection::DESTINATION_CREDIT_FACILITY) {
                $settlement = FacilitySettlement::where('source_type', FacilitySettlement::SOURCE_OTHER_COLLECTION)
                    ->where('source_id', $collection->id)
                    ->posted()
                    ->first();

                if ($settlement) {
                    $this->settlementEngine->reverseSettlement($settlement, $actor);
                }
            }

            if ($collection->journal_entry_id) {
                $entry = JournalEntry::findOrFail($collection->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Cancelled — '.$collection->collection_code);
            }

            if ($collection->isCheque()) {
                foreach ($collection->cheques as $c) {
                    if ($c->isCollected()) {
                        $this->chequeReceivableService->undoCollect($c, $actor);
                    }
                    $c->delete();
                }
            }

            $collection->update([
                'status'           => OtherCollection::STATUS_CANCELLED,
                'journal_entry_id' => null,
            ]);

            AuditLog::record($actor, 'other_collection.cancelled', $collection, $before, $collection->fresh()->toArray());

            return $collection->fresh();
        });
    }

    // ── Private — destination resolution (mirrors CustomerPaymentService) ──

    private function resolveDestinationAccount(OtherCollection $collection): ChartOfAccount
    {
        return match ($collection->money_type) {
            OtherCollection::MONEY_BANK_DEPOSIT, OtherCollection::MONEY_INCOMING_TRANSFER => $this->resolveBankOrFacilityAccount($collection),
            OtherCollection::MONEY_CASH_IN_SAFE => $this->resolveSafeAccount($collection),
            OtherCollection::MONEY_CHEQUE       => $this->resolveNotesReceivableAccount($collection),
            default => throw new \DomainException("Unknown money type: {$collection->money_type}"),
        };
    }

    private function resolveBankOrFacilityAccount(OtherCollection $collection): ChartOfAccount
    {
        if ($collection->destination_type === OtherCollection::DESTINATION_CREDIT_FACILITY) {
            $facility = CreditFacility::where('id', $collection->destination_credit_facility_id)
                ->where('company_id', $collection->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $collection->destination_bank_account_id)
            ->where('company_id', $collection->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The selected bank account does not belong to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveSafeAccount(OtherCollection $collection): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($collection->company_id)
            ->where('id', $collection->safe_account_id)->first();

        return $account ?? throw new \DomainException('The selected Safe account does not belong to this company.');
    }

    private function resolveNotesReceivableAccount(OtherCollection $collection): ChartOfAccount
    {
        return ChartOfAccount::findByCode($collection->company_id, '134001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 134001 (Notes Receivable — Customers) — check the seeder.');
    }

    private function narrationFor(OtherCollection $collection): string
    {
        $typeLabel = OtherCollection::partnerTypeLabel($collection->partner_type);
        return "Other Collection {$collection->collection_code} — {$typeLabel}: {$collection->partner->name_en}";
    }

    private function createChequeReceivable(OtherCollection $collection, ?array $chequeData, User $actor): void
    {
        if (! $chequeData) {
            throw new \DomainException('Cheque details are required for a Cheque collection.');
        }

        // M-31: one collection may hold a batch of post-dated cheques.
        foreach ($this->splitChequeBatch($chequeData, (string) $collection->amount, (string) $collection->amount_functional) as $row) {
            ChequeReceivable::create([
                'company_id'                => $collection->company_id,
                'other_collection_id'       => $collection->id,
                'partner_id'                => $collection->partner_id,
                'drawee_bank_id'            => $row['drawee_bank_id'],
                'cheque_amount'             => $row['amount'],
                // Audit C8: remember the cheque's currency and the EGP value booked, so clearing it can value any FX difference.
                'currency_id'               => $collection->currency_id,
                'exchange_rate'             => $collection->exchange_rate ?: 1,
                'amount_functional'         => $row['amount_functional'],
                'cheque_number'             => $row['cheque_number'],
                'due_date'                  => $row['due_date'],
                'receiving_safe_account_id' => $row['receiving_safe_account_id'],
                'status'                    => ChequeReceivable::STATUS_RECEIVED,
                'created_by'                => $actor->id,
            ]);
        }
    }

    private function generateCollectionCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\OtherCollection::class, 'collection_code', 'OC', $date);
    }
}
