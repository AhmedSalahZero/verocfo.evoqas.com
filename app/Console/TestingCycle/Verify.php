<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  Verify
//
//  The vocabulary a scenario uses to check what the application just
//  did. Every method either records a passed check or raises a
//  Finding — nothing here ever repairs data or adjusts an
//  expectation to make a scenario continue.
//
//  THE RULE THAT SHAPES THIS WHOLE CLASS: an expectation must be
//  computed from the BUSINESS INPUTS, never read back out of the
//  application. Asking the application what the weighted average is
//  and then asserting the weighted average equals it proves nothing.
//  So the scenarios do their own arithmetic in bcmath and hand the
//  answer here; this class only compares.
//
//  TRUE LEDGER vs REPORTED. Two different questions, both asked:
//
//    trueBalance()     sums every journal line, whatever the entry's
//                      status. A reversal and the entry it reverses
//                      net to zero, which is the real economic
//                      position.
//    reportedBalance() applies the status = 'posted' filter every
//                      reporting service uses.
//
//  They differ only when something has been reversed — which is
//  exactly audit finding ACC-002. Keeping both available means the
//  cycle can measure that gap rather than trip over it.
//
//  @layer    Console Support
//  @depends  CycleLog, Finding
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\ProductStockBalance;
use Illuminate\Support\Facades\DB;

class Verify
{
    public function __construct(
        private CycleLog $log,
        private int $companyId,
    ) {
    }

    public function forCompany(int $companyId): self
    {
        return new self($this->log, $companyId);
    }

    // ══════════════════════════════════════════════════════════════
    //  Money comparison
    // ══════════════════════════════════════════════════════════════

    /**
     * The workhorse. $expected must have been calculated by the
     * caller from the business facts.
     */
    public function money(
        string $expected,
        string $actual,
        string $what,
        string $operation,
        array $context = [],
        string $severity = Finding::SEVERITY_HIGH,
        string $prefix = 'CYC'
    ): bool {
        if (bccomp($expected, $actual, 2) === 0) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: $prefix,
            severity: $severity,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $what,
            operation: $operation,
            expected: $expected,
            actual: $actual,
            difference: bcsub($expected, $actual, 4),
            context: $context,
        );

        return false;
    }

    /** Quantities are decimal(15,4), so they compare at 4 places. */
    public function quantity(
        string $expected,
        string $actual,
        string $what,
        string $operation,
        array $context = [],
        string $prefix = 'INV'
    ): bool {
        if (bccomp($expected, $actual, 4) === 0) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: $prefix,
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $what,
            operation: $operation,
            expected: $expected,
            actual: $actual,
            difference: bcsub($expected, $actual, 4),
            context: $context,
        );

        return false;
    }

    /** Unit costs are decimal(15,4) — comparing them at 2 hides real drift. */
    public function unitCost(
        string $expected,
        string $actual,
        string $what,
        string $operation,
        array $context = [],
        string $prefix = 'INV'
    ): bool {
        if (bccomp($expected, $actual, 4) === 0) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: $prefix,
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $what,
            operation: $operation,
            expected: $expected,
            actual: $actual,
            difference: bcsub($expected, $actual, 6),
            context: $context,
        );

        return false;
    }

    public function truth(bool $condition, string $what, string $operation, array $context = [], string $severity = Finding::SEVERITY_HIGH, string $prefix = 'CYC'): bool
    {
        if ($condition) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: $prefix,
            severity: $severity,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $what,
            operation: $operation,
            expected: 'the condition to hold',
            actual: 'it did not',
            context: $context,
        );

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  Ledger reads
    // ══════════════════════════════════════════════════════════════

    /** Signed, debit-positive, every line regardless of entry status. */
    public function trueBalance(int $accountId): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
              where l.company_id = ? and l.chart_of_account_id = ?',
            [$this->companyId, $accountId]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }

    /** The same figure with the reporting layer's status filter applied. */
    public function reportedBalance(int $accountId): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
               join journal_entries je on je.id = l.journal_entry_id
              where l.company_id = ? and l.chart_of_account_id = ?
                and je.status = ?',
            [$this->companyId, $accountId, JournalEntry::STATUS_POSTED]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }

    public function trueBalanceByCode(string $code): string
    {
        $id = $this->accountId($code);

        return $id ? $this->trueBalance($id) : '0.00';
    }

    public function accountId(string $code): ?int
    {
        return ChartOfAccount::findByCode($this->companyId, $code)?->id;
    }

    /**
     * A snapshot of several accounts, so a scenario can express
     * "this operation should move cash down by exactly X" as a delta
     * rather than an absolute — which is what an accountant actually
     * checks, and what survives a long history.
     *
     * @param  array<string, int>  $accounts  label => account id
     * @return array<string, string>
     */
    public function snapshot(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $label => $accountId) {
            $out[$label] = $this->trueBalance($accountId);
        }

        return $out;
    }

    /**
     * @param  array<string, int>     $accounts  label => account id
     * @param  array<string, string>  $before    from snapshot()
     * @param  array<string, string>  $expected  label => expected signed movement
     */
    public function movements(array $accounts, array $before, array $expected, string $operation): bool
    {
        $allGood = true;

        foreach ($expected as $label => $expectedDelta) {
            $actualDelta = bcsub($this->trueBalance($accounts[$label]), $before[$label], 2);

            $ok = $this->money(
                $expectedDelta,
                $actualDelta,
                "Account movement is wrong: {$label}",
                $operation,
                [
                    'account'        => $label,
                    'balance before' => $before[$label],
                    'balance after'  => $this->trueBalance($accounts[$label]),
                ],
                prefix: 'ACC'
            );

            $allGood = $allGood && $ok;
        }

        return $allGood;
    }

    // ══════════════════════════════════════════════════════════════
    //  Journal entries
    // ══════════════════════════════════════════════════════════════

    /** The entry a business document should have produced. */
    public function journalEntryFor(string $sourceType, int $sourceId, string $operation): ?JournalEntry
    {
        $entry = JournalEntry::withoutGlobalScopes()
            ->with('lines')
            ->where('company_id', $this->companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->latest('id')
            ->first();

        if (! $entry) {
            $this->log->finding(
                prefix: 'ACC',
                severity: Finding::SEVERITY_CRITICAL,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "No journal entry was created for {$sourceType} #{$sourceId}",
                operation: $operation,
                expected: "one journal entry with source_type = {$sourceType}",
                actual: 'none found',
                rootCause: 'The posting path either did not run or swallowed its own failure.',
            );

            return null;
        }

        $this->log->checkPassed();

        return $entry;
    }

    /** Debits equal credits on one entry. */
    public function entryBalances(JournalEntry $entry, string $operation): bool
    {
        $debit  = '0.00';
        $credit = '0.00';

        foreach ($entry->lines as $line) {
            $debit  = bcadd($debit, (string) $line->debit, 2);
            $credit = bcadd($credit, (string) $line->credit, 2);
        }

        return $this->money(
            $debit,
            $credit,
            "Journal entry {$entry->entry_number} does not balance",
            $operation,
            ['entry' => $entry->entry_number, 'narration' => (string) $entry->narration],
            Finding::SEVERITY_CRITICAL,
            prefix: 'ACC'
        );
    }

    /** One specific posting inside an entry, by account code. */
    public function entryPosts(
        JournalEntry $entry,
        string $accountCode,
        string $expectedDebit,
        string $expectedCredit,
        string $operation
    ): bool {
        $accountId = $this->accountId($accountCode);

        $debit  = '0.00';
        $credit = '0.00';
        $found  = false;

        foreach ($entry->lines as $line) {
            if ((int) $line->chart_of_account_id === $accountId) {
                $debit  = bcadd($debit, (string) $line->debit, 2);
                $credit = bcadd($credit, (string) $line->credit, 2);
                $found  = true;
            }
        }

        if (! $found) {
            $this->log->finding(
                prefix: 'ACC',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "Journal entry {$entry->entry_number} never touches account {$accountCode}",
                operation: $operation,
                expected: "a line on {$accountCode} (Dr {$expectedDebit} / Cr {$expectedCredit})",
                actual: 'the account does not appear on the entry',
                evidence: $this->renderEntry($entry),
            );

            return false;
        }

        $ok = $this->money($expectedDebit, $debit, "Wrong debit on {$accountCode} in {$entry->entry_number}", $operation, ['entry' => $entry->entry_number], prefix: 'ACC');
        $ok = $this->money($expectedCredit, $credit, "Wrong credit on {$accountCode} in {$entry->entry_number}", $operation, ['entry' => $entry->entry_number], prefix: 'ACC') && $ok;

        return $ok;
    }

    /** The entry is dated where the business event happened. */
    public function entryDated(JournalEntry $entry, string $expectedDate, string $operation): bool
    {
        $actual = $entry->entry_date instanceof \DateTimeInterface
            ? $entry->entry_date->format('Y-m-d')
            : substr((string) $entry->entry_date, 0, 10);

        if ($actual === $expectedDate) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: 'ACC',
            severity: Finding::SEVERITY_MEDIUM,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: "Journal entry {$entry->entry_number} is dated in the wrong period",
            operation: $operation,
            expected: $expectedDate,
            actual: $actual,
            rootCause: 'An entry dated away from its business event lands in the wrong month on every report.',
        );

        return false;
    }

    /** Exactly one entry per document — the duplicate-posting check. */
    public function entryCountFor(string $sourceType, int $sourceId, int $expected, string $operation): bool
    {
        $actual = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->count();

        if ($actual === $expected) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: 'ACC',
            severity: Finding::SEVERITY_CRITICAL,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: $actual > $expected
                ? "{$sourceType} #{$sourceId} posted more than once"
                : "{$sourceType} #{$sourceId} posted fewer entries than expected",
            operation: $operation,
            expected: "{$expected} journal entr(ies)",
            actual: "{$actual}",
        );

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  Ledger-wide invariants
    // ══════════════════════════════════════════════════════════════

    /** Every entry this company owns balances to the cent. */
    public function ledgerBalanced(string $operation): bool
    {
        $unbalanced = DB::select(
            'select je.id, je.entry_number, je.source_type, sum(l.debit) d, sum(l.credit) c
               from journal_entries je
               join journal_entry_lines l on l.journal_entry_id = je.id
              where je.company_id = ?
              group by je.id
             having abs(sum(l.debit) - sum(l.credit)) > 0.004',
            [$this->companyId]
        );

        if ($unbalanced === []) {
            $this->log->checkPassed();

            return true;
        }

        $evidence = implode("\n", array_map(
            fn ($r) => "{$r->entry_number} ({$r->source_type})  Dr {$r->d}  Cr {$r->c}",
            array_slice($unbalanced, 0, 10)
        ));

        $this->log->finding(
            prefix: 'ACC',
            severity: Finding::SEVERITY_CRITICAL,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: count($unbalanced).' journal entr(ies) do not balance',
            operation: $operation,
            expected: 'every entry: total debit = total credit',
            actual: count($unbalanced).' entries out of balance',
            evidence: $evidence,
        );

        return false;
    }

    /** No line may reference another company's chart of accounts. */
    public function noCrossCompanyPostings(string $operation): bool
    {
        $count = (int) DB::selectOne(
            'select count(*) n
               from journal_entry_lines l
               join chart_of_accounts a on a.id = l.chart_of_account_id
              where l.company_id = ?
                and a.company_id is not null
                and a.company_id <> ?',
            [$this->companyId, $this->companyId]
        )->n;

        return $this->truth(
            $count === 0,
            "{$count} journal line(s) post to another company's chart of accounts",
            $operation,
            severity: Finding::SEVERITY_CRITICAL,
            prefix: 'TEN'
        );
    }

    /**
     * Document codes are generated per company and must therefore be
     * unique PER COMPANY. A globally-unique index on the code column
     * is a hard multi-tenant blocker: the second company to raise its
     * first document of that type in a given month collides with the
     * first company's code and the insert fails.
     *
     * Migration 000162 fixed exactly this across the documents that
     * existed then. Tables added afterwards can reintroduce it, and
     * one of them did — which is why this is checked rather than
     * assumed.
     */
    public function documentCodesAreCompanyScoped(string $operation): bool
    {
        // DISCOVERED, not listed. A hard-coded table list only finds
        // the tables someone remembered to add to it — and this defect
        // is precisely the kind that arrives with a NEW table. So the
        // check asks the schema: every table that has a company_id and
        // a *_code column, does its unique index include company_id?
        $unscoped = DB::select(
            "select s.TABLE_NAME tbl,
                    GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) cols
               from INFORMATION_SCHEMA.STATISTICS s
              where s.TABLE_SCHEMA = DATABASE()
                and s.NON_UNIQUE = 0
                and exists (
                      select 1 from INFORMATION_SCHEMA.COLUMNS c
                       where c.TABLE_SCHEMA = s.TABLE_SCHEMA
                         and c.TABLE_NAME = s.TABLE_NAME
                         and c.COLUMN_NAME = 'company_id')
              group by s.TABLE_NAME, s.INDEX_NAME
             having cols like '%\_code'
                and cols not like '%company_id%'"
        );

        if ($unscoped === []) {
            $this->log->checkPassed();

            return true;
        }

        $evidence = implode("\n", array_map(
            fn ($row) => "{$row->tbl}: unique({$row->cols})",
            $unscoped
        ));

        $this->log->finding(
            prefix: 'TEN',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: count($unscoped).' document code(s) are unique platform-wide instead of per company',
            operation: $operation,
            expected: 'a unique index on (company_id, code), as customer_invoices and supplier_bills have',
            actual: 'a unique index on the code column alone',
            evidence: $evidence,
            source: 'the migrations creating these tables, after 000162 fixed the same class of bug elsewhere',
            rootCause: 'Codes are generated from a PER-COMPANY counter, so two companies produce the same code '
                .'in the same month. The second company to insert collides and cannot create the document at '
                .'all — a hard multi-tenant blocker, not a cosmetic issue.',
        );

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  Inventory
    // ══════════════════════════════════════════════════════════════

    /** @return array{quantity: string, value: string, wac: string} */
    public function inventoryState(int $productId): array
    {
        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('product_id', $productId)
            ->first();

        return [
            'quantity' => (string) ($balance->quantity_on_hand ?? '0.0000'),
            'value'    => (string) ($balance->wac_total_value ?? '0.00'),
            'wac'      => (string) ($balance->wac_unit_cost ?? '0.0000'),
        ];
    }

    /**
     * The independent-calculation check: the caller states what the
     * quantity, value and average SHOULD be, having worked it out
     * from the receipts and issues it performed.
     */
    public function inventory(
        int $productId,
        string $expectedQty,
        string $expectedValue,
        string $expectedWac,
        string $productName,
        string $operation
    ): bool {
        $state = $this->inventoryState($productId);

        $context = [
            'product'         => $productName,
            'actual quantity' => $state['quantity'],
            'actual value'    => $state['value'],
            'actual WAC'      => $state['wac'],
        ];

        $ok = $this->quantity($expectedQty, $state['quantity'], "Stock quantity is wrong for {$productName}", $operation, $context);
        $ok = $this->money($expectedValue, $state['value'], "Stock book value is wrong for {$productName}", $operation, $context, prefix: 'INV') && $ok;
        $ok = $this->unitCost($expectedWac, $state['wac'], "Weighted average cost is wrong for {$productName}", $operation, $context) && $ok;

        return $ok;
    }

    /**
     * The subsidiary ledger must reconcile to the general ledger.
     * Run after every batch of inventory movement — it is the single
     * most valuable cross-module check in the system.
     */
    public function inventoryReconcilesToLedger(string $operation): bool
    {
        // Reported once per account. A reconciliation break does not
        // heal itself — once inventory and the ledger diverge they
        // stay diverged — so repeating the same finding every month
        // for the rest of the run buries everything else.
        static $reported = [];

        $rows = DB::select(
            'select p.inventory_account_id aid, a.code, sum(b.wac_total_value) v
               from product_stock_balances b
               join products p on p.id = b.product_id
               left join chart_of_accounts a on a.id = p.inventory_account_id
              where b.company_id = ? and p.inventory_account_id is not null
              group by p.inventory_account_id, a.code',
            [$this->companyId]
        );

        $allGood = true;

        foreach ($rows as $row) {
            $subsidiary = number_format((float) $row->v, 2, '.', '');
            $ledger     = $this->trueBalance((int) $row->aid);

            // A job-order-segmented product legitimately sits outside
            // the pooled balance (audit finding MFG-001), so the
            // segment total is added back before comparing.
            $segment = number_format((float) DB::selectOne(
                'select coalesce(sum(jb.total_value), 0) v
                   from job_order_stock_balances jb
                   join products p on p.id = jb.product_id
                  where jb.company_id = ? and p.inventory_account_id = ?',
                [$this->companyId, $row->aid]
            )->v, 2, '.', '');

            $expected = bcadd($subsidiary, $segment, 2);

            if (bccomp($expected, $ledger, 2) !== 0 && isset($reported[$row->code])) {
                continue;
            }

            if (bccomp($expected, $ledger, 2) !== 0) {
                $reported[$row->code] = true;
            }

            $ok = $this->money(
                $expected,
                $ledger,
                "Inventory subsidiary ledger does not reconcile to GL account {$row->code}",
                $operation,
                [
                    'pooled stock balances' => $subsidiary,
                    'job order segments'    => $segment,
                    'general ledger'        => $ledger,
                ],
                prefix: 'INV'
            );

            $allGood = $allGood && $ok;
        }

        return $allGood;
    }

    /** Stock must never go negative — pooled or per location. */
    public function noNegativeStock(string $operation): bool
    {
        $pooled = DB::select(
            'select p.name_en, b.quantity_on_hand q
               from product_stock_balances b join products p on p.id = b.product_id
              where b.company_id = ? and b.quantity_on_hand < 0',
            [$this->companyId]
        );

        $located = DB::select(
            'select p.name_en, s.quantity_on_hand q
               from product_location_stock s join products p on p.id = s.product_id
              where s.company_id = ? and s.quantity_on_hand < 0',
            [$this->companyId]
        );

        $bad = array_merge($pooled, $located);

        if ($bad === []) {
            $this->log->checkPassed();

            return true;
        }

        $this->log->finding(
            prefix: 'INV',
            severity: Finding::SEVERITY_CRITICAL,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: count($bad).' stock balance(s) went negative',
            operation: $operation,
            expected: 'no negative stock anywhere',
            actual: count($bad).' negative balances',
            evidence: implode("\n", array_map(fn ($r) => "{$r->name_en}: {$r->q}", array_slice($bad, 0, 10))),
        );

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    public function renderEntry(JournalEntry $entry): string
    {
        $lines = ["{$entry->entry_number}  {$entry->entry_date}  {$entry->narration}"];

        foreach ($entry->lines as $line) {
            $account = ChartOfAccount::withoutGlobalScopes()->find($line->chart_of_account_id);
            $lines[] = sprintf(
                '  %-10s %-34s Dr %12s  Cr %12s',
                $account->code ?? '?',
                substr($account->name_en ?? '?', 0, 34),
                $line->debit,
                $line->credit
            );
        }

        return implode("\n", $lines);
    }
}
