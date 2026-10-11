<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JournalEntryService
//
//  The ONLY place in the codebase allowed to write to journal_entries
//  and journal_entry_lines — same "one gatekeeper service" pattern
//  as StockLedgerService for the inventory ledger. Every auto-posting
//  transaction (Goods Receipt Note today; Supplier Bill, Payments,
//  Sales Invoice, Payroll tomorrow) and the future Manual Journal
//  Entry tool (Phase 4) call post() — nothing else touches these
//  two tables directly.
//
//  post() is the whole contract other services need to know:
//
//      $lines[] = [
//          'chart_of_account_id' => 135001,
//          'debit'               => '50000.00',   // string — bcmath
//          'credit'              => '0.00',
//          'cost_center_id'      => null,           // optional
//          'description'         => 'optional memo',
//      ];
//
//      $je = app(JournalEntryService::class)->post(
//          companyId: $companyId,
//          entryDate: $grn->received_date,
//          lines: $lines,
//          sourceType: 'goods_receipt_note',
//          sourceId: $grn->id,
//          narration: "Goods Receipt — {$grn->grn_code}",
//          actor: $actor,
//      );
//
//  GUARANTEES ENFORCED HERE (never left to the caller):
//    - sum(debit) === sum(credit), via bcmath — throws
//      UnbalancedJournalEntryException otherwise. This is the single
//      most important check in the whole Accounting Engine; nothing
//      bypasses it.
//    - every chart_of_account_id is postable (Level 4/5,
//      is_postable = true) and resolvable for this company (system
//      account with company_id = NULL, or the company's own) — via
//      withoutGlobalScopes() + ChartOfAccount::forCompany(), per the
//      platform's documented "Known Recurring Bug" rule. Throws
//      NonPostableAccountException otherwise.
//    - the whole write is one DB::transaction() — an entry with only
//      some of its lines saved can never exist.
//    - the entry date falls inside an OPEN AccountingPeriod for this
//      company (fails safe if no period covers the date at all) —
//      via FiscalYearService::periodForDate(). Throws
//      PeriodClosedException otherwise. (Closed 2026: this was the
//      one documented rule the gatekeeper itself never checked —
//      every calling service now inherits the protection for free.)
//    - AuditLog on every post/reverse (Rule 5).
//    - Audit H12 / H15 (Sept 2026): every line on a single-foreign-
//      currency account (USD bank, EUR loan, …) also records its
//      foreign amount (currency_id / amount_currency / exchange_rate)
//      via ForeignCurrencyLedgerService::enrichLines(). A caller MAY
//      add to a line, all optional:
//          'currency_id'     => 2,          // the line's own currency
//          'amount_currency' => '1000.00',  // exact foreign amount, or
//          'exchange_rate'   => '50.250000',// the rate it converted at
//      Nothing else about post() changes; callers that pass neither
//      get the rate-table rate for the entry date. reverse() copies
//      the original foreign amounts with the sign flipped.
//
//  DELIBERATELY NOT HERE (Phase 4 conveniences, not correctness):
//    - draft state / edit-before-post
//    - recurring/templated entries
//    - reviewer approval workflow
//  A posted entry is immutable. The only way to undo one is
//  reverse(), which creates a second, linked, mirror-image entry —
//  it never edits or deletes the original.
//
//  Account balances (Trial Balance, GL, Balance Sheet — Phase 5) are
//  derived by summing journal_entry_lines directly, not cached here.
//  See migration 000098 header for the reasoning.
//
//  @layer    Service
//  @depends  JournalEntry, JournalEntryLine, ChartOfAccount, User,
//            AuditLog, FiscalYearService, ForeignCurrencyLedgerService
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\NonPostableAccountException;
use App\Exceptions\Accounting\PeriodClosedException;
use App\Exceptions\Accounting\UnbalancedJournalEntryException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryNumberSequence;
use App\Models\User;
use App\Services\FiscalYearService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        private FiscalYearService $fiscalYearService,
    ) {
    }

    // ── Read: minimal Journal Entry viewer (July 2026) ─────────────
    //
    // Read-only — this class still remains the only WRITER to these
    // tables (post()/reverse() above). These two methods just let the
    // person see what's already been posted; e.g. what a Goods
    // Receipt Note actually posted to the GL.

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $entries = JournalEntry::where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($q) => $q
                    ->where('entry_number', 'like', "%{$s}%")
                    ->orWhere('narration', 'like', "%{$s}%")
                )
            )
            ->when($filters['source_type'] ?? null, fn ($q, $t) => $q->where('source_type', $t))
            ->when($filters['journal_type'] ?? null, fn ($q, $t) => $q->where('journal_type', $t))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('entry_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('entry_date', '<=', $d))
            ->withSum('lines as total_debit', 'debit')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $this->attachPartnerNames($entries->getCollection(), $companyId);

        return $entries;
    }

    /**
     * Sets a `partner_name` attribute on each entry — resolved from
     * whatever source document it came from, batched per source_type
     * (never one query per row). Journal Entries have no real
     * polymorphic relation to their source (source_type/source_id is
     * a plain string+id pair used generically across ~30 different
     * document tables — see class header), so this is a best-effort
     * lookup table of "which source types carry a real Partner, and
     * on which column" rather than a genuine Eloquent relation.
     *
     * Deliberately incomplete by design, not oversight: facility,
     * leasing, and factoring COMPANY master records (Bank,
     * LeasingCompany, FactoringCompany) are NOT Partner rows in this
     * schema, so source types like 'leasing_contract' or
     * 'bank_account_td_event' correctly fall through to null/dash —
     * there genuinely is no partner to show. Same for 'reversal',
     * which points back at another journal_entries row, not a
     * document. Confirmed with Mahmoud: "if exists, show it — if
     * not, dash" — this is that rule implemented literally, not an
     * attempt to force every source type to resolve one.
     */
    private function attachPartnerNames($entries, int $companyId): void
    {
        $byType = $entries->groupBy('source_type');
        $partnerIdsBySourceKey = []; // "{source_type}:{source_id}" => partner_id

        foreach ($byType as $sourceType => $group) {
            $ids = $group->pluck('source_id')->filter()->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $resolver = self::partnerIdResolvers()[$sourceType] ?? null;
            if (! $resolver) {
                continue;
            }

            foreach ($resolver($ids) as $sourceId => $partnerId) {
                $partnerIdsBySourceKey["{$sourceType}:{$sourceId}"] = $partnerId;
            }
        }

        $allPartnerIds = collect($partnerIdsBySourceKey)->filter()->unique()->values();
        $partnerNames = $allPartnerIds->isEmpty()
            ? collect()
            : \App\Models\Partner::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('id', $allPartnerIds)->pluck('name_en', 'id');

        foreach ($entries as $entry) {
            $partnerId = $partnerIdsBySourceKey["{$entry->source_type}:{$entry->source_id}"] ?? null;
            $entry->setAttribute('partner_name', $partnerId ? ($partnerNames[$partnerId] ?? null) : null);
        }
    }

    /**
     * source_type => closure(Collection $sourceIds): Collection[id => partner_id]
     * One entry per document table that carries a real partner_id
     * (or equivalent) column. Add a line here whenever a new
     * auto-posting service starts calling post() with a sourceType
     * this map doesn't yet cover and that document DOES have a real
     * partner — until then it correctly shows a dash, per the class
     * header above.
     */
    private static function partnerIdResolvers(): array
    {
        return [
            'accrual'                  => fn ($ids) => \App\Models\Accrual::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'cash_expense_payment'     => fn ($ids) => \App\Models\CashExpensePayment::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'other_collection'         => fn ($ids) => \App\Models\OtherCollection::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'other_payment'            => fn ($ids) => \App\Models\OtherPayment::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'customer_invoice'         => fn ($ids) => \App\Models\CustomerInvoice::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'credit_note'              => fn ($ids) => \App\Models\CreditNote::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'delivery_note'            => fn ($ids) => \App\Models\DeliveryNote::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'supplier_bill'            => fn ($ids) => \App\Models\SupplierBill::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'supplier_payment'         => fn ($ids) => \App\Models\SupplierPayment::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'customer_payment'         => fn ($ids) => \App\Models\CustomerPayment::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'goods_receipt_note'       => fn ($ids) => \App\Models\GoodsReceiptNote::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'lg_issuance'              => fn ($ids) => \App\Models\FinancialInstitutions\LgIssuance::whereIn('id', $ids)->pluck('beneficiary_partner_id', 'id'),
            'factoring_transaction'    => fn ($ids) => \App\Models\FinancialInstitutions\FactoringTransaction::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'reverse_factoring_settle' => fn ($ids) => \App\Models\FinancialInstitutions\ReverseFactoringTransaction::whereIn('id', $ids)->pluck('partner_id', 'id'),
            'reverse_factoring_txn'    => fn ($ids) => \App\Models\FinancialInstitutions\ReverseFactoringTransaction::whereIn('id', $ids)->pluck('partner_id', 'id'),
            // Two-hop: a supplier_bill_line has no partner_id of its own — its parent Supplier Bill does.
            // Covers Prepaid Amortization postings (PrepaidAmortizationService) among other supplier_bill_line sources.
            'supplier_bill_line'       => fn ($ids) => \App\Models\SupplierBillLine::whereIn('id', $ids)
                ->with('supplierBill:id,partner_id')->get()
                ->mapWithKeys(fn ($line) => [$line->id => $line->supplierBill?->partner_id]),
        ];
    }

    public function withLines(int $companyId, JournalEntry $entry): JournalEntry
    {
        abort_unless($entry->company_id === $companyId, 404);

        return $entry->load([
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'lines.chartOfAccount:id,code,name_en,name_ar',
            'lines.costCenter:id,code,name_en',
        ]);
    }

    /**
     * Manual Journal Entry "Set to Draft" (September 2026, per
     * Mahmoud) — deliberately NOT the Customer Collection pattern of
     * reopening the SAME row for editing, because a Manual Journal
     * Entry has no separate document row to reopen — the journal
     * entry IS the record. So this only ever does the one thing a
     * Manual Journal Entry genuinely can do: reverse() it. The
     * "reopen it for editing" experience lives entirely in the Show
     * page afterward — the person edits the reversed entry's lines
     * in the browser and posts them as a NEW entry via createManual()
     * (the same Create form/endpoint), rather than this method
     * somehow resurrecting the old one. Confirmed with Mahmoud: this
     * is intentionally simpler than every other setToDraft() in the
     * app, not an oversight.
     */
    public function setToDraft(JournalEntry $entry, User $actor): JournalEntry
    {
        if (! $entry->isManual()) {
            throw new \DomainException('Only a Manual Journal Entry can be set to draft.');
        }

        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new \DomainException('Only a posted journal entry can be set to draft.');
        }

        if ($this->fiscalYearService->isPeriodClosed($entry->company_id, $entry->entry_date->toDateString())) {
            throw new \DomainException("This entry's accounting period is closed — it cannot be reopened.");
        }

        $this->reverse($entry, $actor, "Set to Draft — {$entry->entry_number}");

        return $this->withLines($entry->company_id, $entry->fresh());
    }

    /** M-38: the accounting period an entry date falls in (null if none is defined). */
    private function periodIdFor(int $companyId, string|Carbon $date): ?int
    {
        $d = $date instanceof Carbon ? $date->toDateString() : substr((string) $date, 0, 10);

        return \App\Models\AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $d)->where('end_date', '>=', $d)
            ->value('id');
    }

    /**
     * Posts a new, balanced journal entry. This is the single entry
     * point every business event in the system uses to write to the
     * GL.
     */
    public function post(
        int $companyId,
        string|Carbon $entryDate,
        array $lines,
        string $sourceType,
        ?int $sourceId,
        string $narration,
        User $actor
    ): JournalEntry {
        if (empty($lines)) {
            throw new \DomainException('A journal entry must have at least one line.');
        }

        $this->assertBalanced($lines);
        $this->assertAllPostable($companyId, $lines);
        $this->assertPeriodOpen($companyId, $entryDate);

        return DB::transaction(function () use ($companyId, $entryDate, $lines, $sourceType, $sourceId, $narration, $actor) {
            $entry = JournalEntry::create([
                'company_id'   => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date'   => $entryDate,
                'accounting_period_id' => $this->periodIdFor($companyId, $entryDate), // M-38
                'source_type'  => $sourceType,
                'source_id'    => $sourceId,
                // journal_type is derived, never chosen by the caller —
                // the ONLY thing that makes an entry 'manual' is going
                // through createManual() below, which always posts
                // source_type = SOURCE_MANUAL. Every one of the ~30
                // auto-posting callers needs zero changes for this.
                'journal_type' => $sourceType === JournalEntry::SOURCE_MANUAL
                    ? JournalEntry::TYPE_MANUAL
                    : JournalEntry::TYPE_AUTOMATED,
                'narration'    => $narration,
                'status'       => JournalEntry::STATUS_POSTED,
                'created_by'   => $actor->id,
            ]);

            $this->createLines($entry, $companyId, $this->foreignLedger()->enrichLines(
                $companyId,
                $entryDate instanceof Carbon ? $entryDate->toDateString() : (string) $entryDate,
                $lines
            ));

            AuditLog::record($actor, 'journal_entry.posted', $entry, null, $entry->fresh('lines')->toArray());

            return $entry->fresh(['lines.chartOfAccount']);
        });
    }

    /**
     * Manual Journal Entry (Phase 4 exception tool, September 2026) —
     * the one place a person builds journal_entry_lines by hand
     * instead of a business document doing it automatically.
     * Reserved for adjustments/accruals/corrections, per the
     * Master Project Summary's own description of what this tool is
     * for. Immediately posted, same as everything else — there is no
     * draft state (see class header "DELIBERATELY NOT HERE"); the
     * only way to undo a wrong manual entry is reverse() below.
     *
     * $debitLines / $creditLines are each an array of:
     *   ['chart_of_account_id' => int, 'amount' => string,
     *    'cost_center_id' => ?int, 'description' => ?string]
     *
     * Deliberately thin: just reshapes two repeaters into the one
     * lines[] contract post() already understands, then calls post()
     * — every guarantee post() enforces (balanced, postable accounts,
     * open period) applies here automatically, with zero duplicated
     * logic.
     */
    public function createManual(
        int $companyId,
        string|Carbon $entryDate,
        array $debitLines,
        array $creditLines,
        string $narration,
        User $actor
    ): JournalEntry {
        // Audit H3: control accounts can never take a manual posting —
        // re-checked here as well as when the request was submitted.
        $this->assertNoControlAccounts($companyId, $this->buildManualLines($debitLines, $creditLines));

        return $this->post(
            companyId: $companyId,
            entryDate: $entryDate,
            lines: $this->buildManualLines($debitLines, $creditLines),
            sourceType: JournalEntry::SOURCE_MANUAL,
            sourceId: null,
            narration: $narration,
            actor: $actor,
        );
    }

    /**
     * Audit H3 (Sept 2026) — runs every check a manual journal entry must
     * pass WITHOUT posting it, so a request is refused at submission
     * time rather than only when the approver approves it: balanced,
     * every account postable and this company's, the period open, and
     * no control account (AR, AP, inventory, …) touched.
     */
    public function validateManual(int $companyId, string|Carbon $entryDate, array $debitLines, array $creditLines): void
    {
        $lines = $this->buildManualLines($debitLines, $creditLines);

        if (empty($lines)) {
            throw new \DomainException('A journal entry must have at least one line.');
        }

        $this->assertBalanced($lines);
        $this->assertAllPostable($companyId, $lines);
        $this->assertPeriodOpen($companyId, $entryDate);
        $this->assertNoControlAccounts($companyId, $lines);
    }

    /** Audit H3 — refuses any line on an account flagged blocks_manual_posting. */
    private function assertNoControlAccounts(int $companyId, array $lines): void
    {
        $ids = array_unique(array_map(fn ($l) => (int) $l['chart_of_account_id'], $lines));

        $blocked = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->whereIn('id', $ids)
            ->where('blocks_manual_posting', true)
            ->get(['code', 'name_en']);

        if ($blocked->isNotEmpty()) {
            $list = $blocked->map(fn ($a) => "{$a->code} {$a->name_en}")->implode(', ');
            throw new \DomainException(
                "A manual journal entry cannot post to a control account ({$list}). ".
                'Use the proper document instead — a credit / debit note, a stock adjustment, or the payment itself.'
            );
        }
    }

    private function buildManualLines(array $debitLines, array $creditLines): array
    {
        $lines = [];

        foreach ($debitLines as $line) {
            $lines[] = [
                'chart_of_account_id' => $line['chart_of_account_id'],
                'debit'               => (string) $line['amount'],
                'credit'              => '0',
                'cost_center_id'      => $line['cost_center_id'] ?? null,
                'description'         => $line['description'] ?? null,
            ];
        }

        foreach ($creditLines as $line) {
            $lines[] = [
                'chart_of_account_id' => $line['chart_of_account_id'],
                'debit'               => '0',
                'credit'              => (string) $line['amount'],
                'cost_center_id'      => $line['cost_center_id'] ?? null,
                'description'         => $line['description'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Reverses a posted entry by creating the exact mirror image
     * (debits and credits swapped) — never edits or deletes the
     * original. The original is flagged 'reversed' and linked both
     * ways to the new entry.
     *
     * ACC-003 fix (confirmed with Mahmoud, 2026-09) — two decisions:
     *   1. A closed accounting period is fully locked, reversal
     *      included. Same guard post() already enforces, applied here
     *      too, checked against the ORIGINAL entry's date (the period
     *      being reopened, not "today").
     *   2. The reversal is dated identically to the entry it
     *      reverses — not today's date — so a correction always stays
     *      in the same month as the mistake it corrects. Because of
     *      decision 1, that period is guaranteed to still be open by
     *      the time this line runs, so this can never itself fail the
     *      period check it just passed.
     */
    public function reverse(JournalEntry $entry, User $actor, ?string $narration = null): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new \DomainException('Only a posted journal entry can be reversed.');
        }

        // OB-001 decision (confirmed with Mahmoud, 2026-09) — the
        // opening entry is not reversible. It is the entry the company
        // was migrated in on; the Opening Balances session locks itself
        // behind is_posted for the same reason, and OpeningBalanceService
        // already describes the action as "one-time, irreversible" in
        // its own audit trail. A later correction belongs in an ordinary
        // adjusting entry that says what is being corrected and why —
        // not in a rewrite of the company's starting position.
        if ($entry->source_type === JournalEntry::SOURCE_OPENING_BALANCE) {
            throw new \DomainException(
                'The opening balance entry cannot be reversed — it is how this company was '.
                'migrated into the system. Post an adjusting journal entry instead.'
            );
        }

        $this->assertPeriodOpen($entry->company_id, $entry->entry_date);

        return DB::transaction(function () use ($entry, $actor, $narration) {
            $before = $entry->toArray();

            // Audit H12/H15: the foreign amount is mirrored exactly (sign
            // flipped) — never re-derived at a different rate.
            $mirrorLines = $entry->lines->map(fn ($line) => [
                'chart_of_account_id' => $line->chart_of_account_id,
                'cost_center_id'      => $line->cost_center_id,
                'debit'               => (string) $line->credit,
                'credit'              => (string) $line->debit,
                'description'         => $line->description,
                'currency_id'         => $line->currency_id,
                'amount_currency'     => $line->amount_currency === null ? null : bcmul((string) $line->amount_currency, '-1', 2),
                'exchange_rate'       => $line->exchange_rate,
            ])->all();

            $reversal = JournalEntry::create([
                'company_id'                => $entry->company_id,
                'entry_number'              => $this->generateEntryNumber($entry->company_id),
                'entry_date'                => $entry->entry_date,
                'accounting_period_id'      => $entry->accounting_period_id,
                'source_type'               => 'reversal',
                'source_id'                 => $entry->id,
                // Mirrors the journal_type of the entry being reversed —
                // reversing a manual entry is itself a manual action;
                // reversing an auto-posted one stays system-flavored.
                'journal_type'              => $entry->journal_type,
                'narration'                 => $narration ?? "Reversal of {$entry->entry_number}",
                'status'                    => JournalEntry::STATUS_POSTED,
                'reverses_journal_entry_id' => $entry->id,
                'created_by'                => $actor->id,
            ]);

            $this->createLines($reversal, $entry->company_id, $mirrorLines);

            $entry->update([
                'status'                       => JournalEntry::STATUS_REVERSED,
                'reversed_by_journal_entry_id' => $reversal->id,
            ]);

            AuditLog::record($actor, 'journal_entry.reversed', $entry, $before, $entry->fresh()->toArray());

            return $reversal->fresh(['lines.chartOfAccount']);
        });
    }

    // ── Private helpers ───────────────────────────────────────────

    private function assertBalanced(array $lines): void
    {
        $totalDebit  = '0';
        $totalCredit = '0';

        foreach ($lines as $line) {
            $totalDebit  = bcadd($totalDebit, (string) ($line['debit'] ?? '0'), 2);
            $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? '0'), 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new UnbalancedJournalEntryException(
                "Journal entry does not balance: total debit {$totalDebit} != total credit {$totalCredit}."
            );
        }
    }

    private function assertAllPostable(int $companyId, array $lines): void
    {
        $accountIds = array_unique(array_column($lines, 'chart_of_account_id'));

        // Known Recurring Bug rule: system accounts have company_id = NULL
        // and must be reached with withoutGlobalScopes() + forCompany().
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        foreach ($accountIds as $id) {
            $account = $accounts->get($id);

            if (! $account || ! $account->is_postable) {
                throw new NonPostableAccountException(
                    "Chart of account #{$id} is not a valid postable account for this company."
                );
            }
        }
    }

    /**
     * Enforces the AccountingPeriod lifecycle documented on the
     * model itself: "closed → no new postings" — for real, at the
     * one place every posting must pass through.
     *
     * Fails safe: a date that falls inside no AccountingPeriod at
     * all (e.g. before the company's first fiscal year, or a typo)
     * is blocked too, same as FiscalYearService::isPeriodClosed().
     * Uses periodForDate() directly (rather than isPeriodClosed())
     * so the error message can name the period when one exists.
     */
    private function assertPeriodOpen(int $companyId, string|Carbon $entryDate): void
    {
        $date = $entryDate instanceof Carbon ? $entryDate->toDateString() : (string) $entryDate;

        $period = $this->fiscalYearService->periodForDate($companyId, $date);

        if (! $period) {
            throw new PeriodClosedException(
                "Cannot post — {$date} does not fall inside any accounting period for this company."
            );
        }

        if ($period->isClosed()) {
            throw new PeriodClosedException(
                "Cannot post — the accounting period [{$period->name}] covering {$date} is closed."
            );
        }
    }

    /** Resolved lazily so existing constructor wiring is unchanged. */
    private function foreignLedger(): ForeignCurrencyLedgerService
    {
        return app(ForeignCurrencyLedgerService::class);
    }

    private function createLines(JournalEntry $entry, int $companyId, array $lines): void
    {
        foreach ($lines as $i => $line) {
            JournalEntryLine::create([
                'company_id'          => $companyId,
                'journal_entry_id'    => $entry->id,
                'chart_of_account_id' => $line['chart_of_account_id'],
                'cost_center_id'      => $line['cost_center_id'] ?? null,
                'debit'               => (string) ($line['debit'] ?? '0'),
                'credit'              => (string) ($line['credit'] ?? '0'),
                'description'         => $line['description'] ?? null,
                'sort_order'          => $i + 1,
                'currency_id'         => $line['currency_id'] ?? null,
                'amount_currency'     => $line['amount_currency'] ?? null,
                'exchange_rate'       => $line['exchange_rate'] ?? null,
            ]);
        }
    }

    /**
     * ACC-004 fix (confirmed with Mahmoud, 2026-09) — this used to be
     * a plain COUNT(*) + 1, which two people posting for the same
     * company in the same month at the same instant could both read
     * before either had saved, producing the same number and a
     * unique-constraint failure for whoever saved second.
     *
     * Fixed by construction, not by catching the failure afterward:
     * JournalEntryNumberSequence has one row per company per
     * calendar year/month specifically so there is always a real row
     * to lock (lockForUpdate) before reading and incrementing it — a
     * COUNT(*) has nothing to lock. This method always runs inside
     * post()'s or reverse()'s own DB::transaction(), so the lock is
     * held for the whole posting operation and released automatically
     * on commit — the second concurrent caller simply waits its turn
     * and receives the correct next number, with no error ever
     * surfacing to either user. Same row-locking discipline
     * StockLedgerService already uses for stock balances, applied
     * here for the same reason.
     *
     * Numbering is still derived from "now" (when the posting
     * happens), matching the previous behavior's confirmed intent —
     * not from entry_date, so a January transaction posted in
     * September is still numbered JE/2026/09/….
     */
    private function generateEntryNumber(int $companyId): string
    {
        $year  = (int) now()->format('Y');
        $month = (int) now()->format('m');

        $sequence = JournalEntryNumberSequence::where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            try {
                $sequence = JournalEntryNumberSequence::create([
                    'company_id'  => $companyId,
                    'year'        => $year,
                    'month'       => $month,
                    'last_number' => $this->highestExistingEntryNumber($companyId, $year, $month),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Someone else created this period's row a moment ago
                // — the very first entry of a new month, posted by two
                // people at once. Re-fetch and lock the row they just
                // created instead of failing.
                $sequence = JournalEntryNumberSequence::where('company_id', $companyId)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $sequence->increment('last_number');

        return sprintf('JE/%d/%02d/%05d', $year, $month, $sequence->last_number);
    }

    /**
     * The highest number already issued for this company in this
     * calendar month, read straight off journal_entries.
     *
     * A brand-new sequence row must NOT start at zero. Every company
     * that was already posting before the sequence table existed has
     * entry numbers in journal_entries and no row here — starting at
     * zero hands out JE/YYYY/MM/00001 again, which the
     * (company_id, entry_number) unique index rejects, and since the
     * increment is inside the same transaction that then rolls back,
     * the next attempt asks for exactly the same number: posting is
     * blocked permanently rather than transiently. Seeding the new
     * row from what has actually been issued makes the switch-over
     * invisible, and covers a restored or imported database the same
     * way. Runs once per company per month — only when the row is
     * first created — so the scan cost is irrelevant.
     */
    private function highestExistingEntryNumber(int $companyId, int $year, int $month): int
    {
        $prefix = sprintf('JE/%d/%02d/', $year, $month);

        return (int) JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', $prefix.'%')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING_INDEX(entry_number, '/', -1) AS UNSIGNED)), 0) as highest")
            ->value('highest');
    }
}