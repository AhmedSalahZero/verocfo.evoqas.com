<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  ReversalReportingTest
//
//  What every financial report shows after a posted document is
//  reopened and its journal entry reversed.
//
//  How a reversal is recorded (JournalEntryService::reverse()):
//    - a mirror-image entry is created with status = 'posted' and
//      reverses_journal_entry_id pointing at the original;
//    - the ORIGINAL entry's status is changed to 'reversed'.
//
//  Both rows stay in journal_entry_lines, and together they net to
//  zero — the ledger itself is correct and self-consistent.
//
//  Every reporting service USED to filter on
//  journal_entries.status = 'posted' (AccountBalanceCalculator:60,
//  TrialBalanceService:204 and :252, IncomeStatementService:245,
//  LedgerStatementService:111, :187, :244, :300), which kept the
//  mirror image and dropped the entry it cancels — applying the
//  transaction a second time in the opposite direction instead of
//  cancelling it (audit finding ACC-002, FIXED 2026-09).
//
//  These tests now pin the fixed behaviour: every report counts both
//  rows, so a reversal nets to zero everywhere. Each one also shows,
//  via preAcc002FilteredBalanceById(), what the old filter would
//  have produced — so the tests keep proving the difference rather
//  than merely asserting a number.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class ReversalReportingTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    /**
     * Arrange: receive 7,000.00 of stock, then receive a further
     * 4,100.00 and immediately reopen that second receipt.
     * Economically the company holds 7,000.00 of stock.
     */
    private function receiveThenReverse(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $grn = $this->scenario->receiveStock([[$product, 100, 41.00]], '2026-01-02');

        app(GoodsReceiptNoteService::class)->setToDraft($grn, $this->actor);
    }

    /**
     * The ledger itself is correct: original + reversal net to zero,
     * every entry balances, and the true account balance is right.
     */
    public function test_the_ledger_itself_is_correct_after_a_reversal(): void
    {
        $this->receiveThenReverse();
        $product = $this->scenario->products[0];

        $this->assertLedgerBalanced($this->scenario->companyId, 'After a reversal.');

        $this->assertMoneyEquals(
            '7000.00',
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'Summing every line, the true inventory balance is the first receipt only.'
        );

        // The two entries exist and are linked.
        $original = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('status', JournalEntry::STATUS_REVERSED)
            ->firstOrFail();

        $reversal = JournalEntry::withoutGlobalScopes()
            ->where('reverses_journal_entry_id', $original->id)
            ->firstOrFail();

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
    }

    /**
     * The stock ledger also reverses correctly — so any divergence
     * from here on is purely a reporting problem.
     */
    public function test_the_stock_ledger_is_correct_after_a_reversal(): void
    {
        $this->receiveThenReverse();
        $product = $this->scenario->products[0];

        $balance = \App\Models\ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertQuantityEquals('200', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('7000.00', (string) $balance->wac_total_value);
    }

    /**
     * REGRESSION GUARD — audit finding ACC-002, FIXED 2026-09.
     *
     * Independent expectation:
     *   true inventory balance                      7,000.00
     *   reported inventory balance                  7,000.00
     *   what the old status = 'posted' filter gave  2,900.00
     *
     * The second assertion is the one that matters; the third exists
     * so this test still demonstrates the defect it guards against,
     * rather than asserting a number that could look right for an
     * unrelated reason.
     */
    public function test_reports_no_longer_understate_the_balance_after_a_reversal(): void
    {
        $this->receiveThenReverse();
        $product = $this->scenario->products[0];
        $accountId = (int) $product->inventory_account_id;

        $true    = $this->glBalanceById($this->scenario->companyId, $accountId);
        $preFix  = $this->preAcc002FilteredBalanceById($this->scenario->companyId, $accountId);

        $this->assertMoneyEquals('7000.00', $true, 'The ledger holds 7,000.00.');

        $reported = app(\App\Services\Accounting\AccountBalanceCalculator::class)
            ->balancesAsOf(
                $this->scenario->companyId,
                '2026-12-31',
                \App\Models\ChartOfAccount::withoutGlobalScopes()
                    ->where('id', $accountId)->get()->keyBy('id')
            )[$accountId];

        $this->assertMoneyEquals(
            $true,
            $reported,
            'ACC-002 fix: the shared balance engine counts the reversed original '.
            'alongside its mirror image, so the pair nets to zero and the reported '.
            'balance equals the true ledger.'
        );

        $this->assertMoneyEquals(
            '2900.00',
            $preFix,
            'Guard the guard: the old status = \'posted\' filter still reproduces '.
            'the wrong figure on this same data, so the assertion above is '.
            'genuinely discriminating.'
        );
    }

    /**
     * The same behaviour, measured through the application's own
     * Trial Balance service rather than a hand-written query — this
     * is the number a user actually sees on screen.
     */
    public function test_trial_balance_shows_the_true_inventory(): void
    {
        $this->receiveThenReverse();
        $product = $this->scenario->products[0];

        $report = app(TrialBalanceService::class)->build($this->scenario->companyId, [[
            'key'        => 'p',
            'label'      => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]]);

        $row = collect($report['rows'])
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $product->inventory_account_id);

        $this->assertNotNull($row, 'The inventory account must appear on the trial balance.');

        $this->assertMoneyEquals(
            '11100.00',
            (string) $row['periods']['p']['debit'],
            'ACC-002 fix: both receipts are counted as debits — the reversed one '.
            'included, because the goods genuinely were received.'
        );
        $this->assertMoneyEquals(
            '4100.00',
            (string) $row['periods']['p']['credit'],
            'And its reversal is counted as the offsetting credit.'
        );

        $this->assertMoneyEquals(
            '7000.00',
            (string) $row['periods']['p']['ending'],
            'ACC-002 fix: the Trial Balance reports the 7,000.00 the company '.
            'actually holds, not 2,900.00.'
        );
    }

    /**
     * The Trial Balance balanced before the fix too — which is
     * exactly why ACC-002 was invisible: the reversal entry is
     * internally balanced, so dropping the original removed equal
     * debits and credits and the totals still agreed while the
     * individual account balances were wrong.
     *
     * So "it balances" is not the assertion that proves anything
     * here. The second half is: the reported balance and the true
     * ledger balance must now be identical, not merely consistent.
     */
    public function test_the_trial_balance_balances_and_the_accounts_are_right(): void
    {
        $this->receiveThenReverse();

        $report = app(TrialBalanceService::class)->build($this->scenario->companyId, [[
            'key'        => 'p',
            'label'      => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]]);

        $this->assertMoneyEquals(
            (string) $report['totals']['p']['debit'],
            (string) $report['totals']['p']['credit'],
            'The trial balance still balances — which is exactly why this defect is '.
            'invisible to the usual integrity check.'
        );

        // ...and the underlying account now agrees with the ledger.
        $product = $this->scenario->products[0];
        $accountId = (int) $product->inventory_account_id;

        $row = collect($report['rows'])
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === $accountId);

        $this->assertMoneyEquals(
            $this->glBalanceById($this->scenario->companyId, $accountId),
            (string) $row['periods']['p']['ending'],
            'ACC-002 fix: the reported inventory balance equals the true balance, '.
            'so the trial balance balancing is now evidence of correctness rather '.
            'than a coincidence that hides an error.'
        );
    }

    /**
     * Scope of the fix: ACC-002 lived in eight filter sites across
     * four services, and a partial fix is worse than none — the
     * reports would then disagree with each other rather than all
     * being wrong in the same direction.
     *
     * This asserts the filter is gone from every one of them, so
     * reintroducing it anywhere is caught here even if no numeric
     * test happens to cover that particular report.
     */
    public function test_no_reporting_service_filters_reversed_entries_out(): void
    {
        $services = [
            'AccountBalanceCalculator.php',
            'TrialBalanceService.php',
            'IncomeStatementService.php',
            'LedgerStatementService.php',
        ];

        foreach ($services as $file) {
            $source = file_get_contents(base_path('app/Services/Accounting/'.$file));

            // Comments explaining the fix legitimately name the old
            // constant, so strip them before looking at the code.
            $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $source);

            $this->assertStringNotContainsString(
                'STATUS_POSTED',
                $code,
                "{$file} filters journal entries on status again. That is audit "
                .'finding ACC-002: it keeps a reversal but drops the entry the '
                .'reversal cancels, so the transaction is applied a second time in '
                .'the opposite direction instead of being cancelled.'
            );
        }
    }

    /**
     * Idempotency: a document that has already been reopened cannot
     * be reopened again, so the reversal cannot be compounded.
     */
    public function test_a_reopened_document_cannot_be_reopened_again(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $grn = $this->scenario->receiveStock([[$product, 100, 41.00]], '2026-01-02');

        app(GoodsReceiptNoteService::class)->setToDraft($grn, $this->actor);

        $reversalCount = fn () => DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'reversal')
            ->count();

        $before = $reversalCount();

        try {
            app(GoodsReceiptNoteService::class)->setToDraft($grn->fresh(), $this->actor);
            $this->fail('Reopening an already-draft receipt should be refused.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\DomainException::class, $e);
        }

        $this->assertSame(
            $before,
            $reversalCount(),
            'A refused second reopen must not create another reversal entry.'
        );
    }
}
