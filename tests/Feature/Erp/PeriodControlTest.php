<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  PeriodControlTest
//
//  Accounting-period controls: what the system will and will not let
//  you post, and when.
//
//  JournalEntryService::post() enforces, as its own header states,
//  that "the entry date falls inside an OPEN AccountingPeriod for
//  this company (fails safe if no period covers the date at all)".
//  These tests check that guard from both sides, and then check
//  whether reverse() — the other write path into the same two
//  tables — is held to the same standard.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\PeriodClosedException;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class PeriodControlTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    private function closePeriodCovering(string $date): AccountingPeriod
    {
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->firstOrFail();

        $period->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        return $period->fresh();
    }

    private function postSimpleEntry(string $date): JournalEntry
    {
        // 111001 Land and 311 Paid Up Capital are both Level 4/5
        // postable system accounts — a minimal balanced entry that
        // exercises the period guard without depending on any
        // module's own account resolution.
        $asset = $this->scenario->coaId('111001');

        return app(JournalEntryService::class)->post(
            companyId: $this->scenario->companyId,
            entryDate: $date,
            lines: [
                ['chart_of_account_id' => $asset, 'debit' => '100.00', 'credit' => '0', 'description' => 'test'],
                ['chart_of_account_id' => $this->scenario->coaId('311'), 'debit' => '0', 'credit' => '100.00', 'description' => 'test'],
            ],
            sourceType: 'manual_journal_entry',
            sourceId: null,
            narration: 'Period control probe',
            actor: $this->actor,
        );
    }

    /** Control: an open period accepts the posting. */
    public function test_posting_into_an_open_period_succeeds(): void
    {
        $entry = $this->postSimpleEntry('2026-03-15');

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /** A closed period must refuse the posting. */
    public function test_posting_into_a_closed_period_is_refused(): void
    {
        $this->closePeriodCovering('2026-03-15');

        $this->expectException(PeriodClosedException::class);
        $this->expectExceptionMessageMatches('/closed/i');

        $this->postSimpleEntry('2026-03-15');
    }

    /** A date no period covers must also be refused — fail safe. */
    public function test_posting_outside_every_period_is_refused(): void
    {
        $this->expectException(PeriodClosedException::class);

        // The company's first fiscal year is 2026; 2019 is covered by
        // no period at all.
        $this->postSimpleEntry('2019-05-01');
    }

    /** Closing a period must not silently discard what is already in it. */
    public function test_closing_a_period_preserves_entries_already_posted(): void
    {
        $this->postSimpleEntry('2026-03-15');

        $before = DB::table('journal_entry_lines')
            ->where('company_id', $this->scenario->companyId)->count();

        $this->closePeriodCovering('2026-03-15');

        $this->assertSame(
            $before,
            DB::table('journal_entry_lines')
                ->where('company_id', $this->scenario->companyId)->count(),
            'Closing a period must never delete or alter posted lines.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * REGRESSION GUARD — audit finding ACC-003, FIXED 2026-09.
     *
     * JournalEntryService::reverse() used to write straight into
     * journal_entries without calling assertPeriodOpen(), dating the
     * reversal now()->toDateString(). A document could therefore be
     * reopened into a period that had been formally closed — which
     * post() would have refused for the same date — and the two
     * halves of one correction landed in different months.
     *
     * Both halves of the fix are pinned here: the period guard is
     * checked against the ORIGINAL entry's date, and the reversal
     * carries that same date. This test closes the period covering
     * TODAY, which under the old behaviour was the period the
     * reversal would have landed in; the January receipt reverses
     * successfully anyway, dated January, because today's closure is
     * irrelevant to it.
     */
    public function test_reversal_is_dated_in_the_period_it_reverses_not_today(): void
    {
        $product = $this->scenario->products[0];
        $grn = $this->scenario->receiveStock([[$product, 10, 5.00]], '2026-01-15');

        $today = now()->toDateString();

        // If today falls outside this company's fiscal year the guard
        // is not the thing under test, so skip rather than mislead.
        $covering = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if (! $covering) {
            $this->markTestSkipped("No accounting period covers today ({$today}) for this company.");
        }

        $this->closePeriodCovering($today);

        $reversalsBefore = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'reversal')->count();

        app(GoodsReceiptNoteService::class)->setToDraft($grn, $this->actor);

        $reversal = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'reversal')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(
            $reversalsBefore + 1,
            DB::table('journal_entries')
                ->where('company_id', $this->scenario->companyId)
                ->where('source_type', 'reversal')->count(),
            'The reversal belongs in January, which is open, so closing today\'s '.
            'period must not block it.'
        );

        $this->assertSame(
            '2026-01-15',
            (string) $reversal->entry_date,
            'ACC-003 fix: the reversal carries the date of the entry it reverses, '.
            'so a correction always stays in the same month as the mistake it '.
            'corrects — it is no longer dated today.'
        );

        $this->assertNotSame(
            $today,
            (string) $reversal->entry_date,
            'Guard the guard: today and the original entry date must genuinely '.
            'differ, or this test proves nothing.'
        );
    }

    /**
     * The other half of ACC-003: a closed period is now closed to
     * reversals too, not only to fresh postings.
     *
     * Closing JANUARY — the period the reversal would land in — must
     * refuse the reopen outright, exactly as post() already refuses a
     * new entry dated into a closed period.
     */
    public function test_reversal_is_refused_when_the_original_period_is_closed(): void
    {
        $product = $this->scenario->products[0];
        $grn = $this->scenario->receiveStock([[$product, 10, 5.00]], '2026-01-15');

        $this->closePeriodCovering('2026-01-15');

        $reversalsBefore = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'reversal')->count();

        try {
            app(GoodsReceiptNoteService::class)->setToDraft($grn, $this->actor);
            $this->fail('Reopening a document whose period is closed must be refused.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression('/period/i', $e->getMessage());
        }

        $this->assertSame(
            $reversalsBefore,
            DB::table('journal_entries')
                ->where('company_id', $this->scenario->companyId)
                ->where('source_type', 'reversal')->count(),
            'ACC-003 fix: no reversal entry may be left behind by a refused reopen.'
        );
    }

    /**
     * The cross-period consequence, now closed: reopening a January
     * document leaves BOTH halves of the correction inside January,
     * so the month still tells the truth about itself.
     *
     * Under ACC-003 the original entry was flipped to 'reversed' and
     * dropped from the January figure while its offsetting credit
     * landed months later, so January silently fell from 1,500.00 to
     * 1,000.00. Both the ACC-002 and ACC-003 fixes are needed for
     * the figure below: the reversal is dated in January (ACC-003),
     * and the reversed original is still counted alongside it
     * (ACC-002), so the two net to zero exactly where they belong.
     */
    public function test_reopening_leaves_both_halves_inside_the_same_period(): void
    {
        $product = $this->scenario->products[0];
        $accountId = (int) $product->inventory_account_id;

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-10');
        $grn = $this->scenario->receiveStock([[$product, 50, 10.00]], '2026-01-20');

        $januaryBefore = $this->reportedBalanceInRange($accountId, '2026-01-01', '2026-01-31');
        $this->assertMoneyEquals('1500.00', $januaryBefore, 'January holds both receipts.');

        app(GoodsReceiptNoteService::class)->setToDraft($grn, $this->actor);

        $januaryAfter = $this->reportedBalanceInRange($accountId, '2026-01-01', '2026-01-31');

        $this->assertMoneyEquals(
            '1000.00',
            $januaryAfter,
            'January drops by exactly the reopened receipt (1,500.00 - 500.00) and '.
            'by nothing else: the reversal is inside January too, so the month is '.
            'complete and self-consistent rather than half-corrected.'
        );
    }

    private function reportedBalanceInRange(int $accountId, string $from, string $to): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
               join journal_entries je on je.id = l.journal_entry_id
              where l.company_id = ? and l.chart_of_account_id = ?
                and je.entry_date between ? and ?',
            [$this->scenario->companyId, $accountId, $from, $to]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }
}
