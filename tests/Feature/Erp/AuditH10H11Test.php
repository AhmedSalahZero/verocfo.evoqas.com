<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditH10H11Test
//
//  Regression tests for two High audit findings fixed in Sept 2026:
//    H10 — a cancelled (reversed) expense must not show up as a
//          negative expense in Expense Analysis.
//    H11 — customer and supplier statements open with a "balance
//          brought forward" row, so the closing balance is right for
//          any date range, not only one starting on go-live day.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\CustomerBalanceService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\SupplierBalanceService;
use App\Services\Analytics\ExpenseAnalysisService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditH10H11Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
    }

    // ── H10 ───────────────────────────────────────────────────────

    public function test_a_reversed_expense_disappears_from_expense_analysis(): void
    {
        $cid = $this->s->companyId;
        $je  = app(JournalEntryService::class);

        $kept = $je->createManual($cid, '2026-03-10', [['chart_of_account_id' => $this->s->coaId('543'), 'amount' => '1000']],
            [['chart_of_account_id' => $this->s->coaId('229'), 'amount' => '1000']], 'Kept expense', $this->actor);
        $cancelled = $je->createManual($cid, '2026-03-15', [['chart_of_account_id' => $this->s->coaId('543'), 'amount' => '400']],
            [['chart_of_account_id' => $this->s->coaId('229'), 'amount' => '400']], 'Cancelled expense', $this->actor);
        $je->reverse($cancelled, $this->actor);

        $report = app(ExpenseAnalysisService::class)->accountBreakdown($cid, ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);

        $this->assertEqualsWithDelta(1000.0, $report['total_expense'], 0.001,
            'H10: only the kept expense counts — the cancelled one and its reversal both drop out.');
        foreach ($report['rows'] as $row) {
            $this->assertGreaterThanOrEqual(0, $row['value'], 'H10: no expense line may show as negative because of a reversal.');
        }
        $this->assertNotNull($kept->id);
    }

    // ── H11 ───────────────────────────────────────────────────────

    public function test_customer_statement_opens_with_balance_brought_forward(): void
    {
        $product = $this->s->products[0];
        $this->s->receiveStock([[$product, 20, 10.00]], '2026-01-10');

        $so1 = $this->s->createSalesOrder([[$product, 2, 50.00]], '2026-02-01');
        $this->s->deliver($so1, '2026-02-05');
        $feb = $this->s->invoice($so1, '2026-02-05', 0);   // 100.00 — BEFORE the range

        $so2 = $this->s->createSalesOrder([[$product, 3, 50.00]], '2026-03-01');
        $this->s->deliver($so2, '2026-03-05');
        $mar = $this->s->invoice($so2, '2026-03-05', 0);    // 150.00 — inside the range

        $rows = app(CustomerBalanceService::class)->statement($this->s->companyId, (int) $feb->partner_id, null, '2026-03-01', '2026-03-31');

        $this->assertTrue($rows[0]['is_opening'], 'H11: the first row is the opening balance.');
        $this->assertMoneyEquals((string) $feb->fresh()->invoice_value_functional, $rows[0]['end_balance'], 'H11: February\'s invoice is brought forward.');
        $this->assertCount(2, $rows);
        $this->assertMoneyEquals(
            bcadd((string) $feb->fresh()->invoice_value_functional, (string) $mar->fresh()->invoice_value_functional, 2),
            end($rows)['end_balance'],
            'H11: closing balance includes the brought-forward amount.'
        );
    }

    public function test_supplier_statement_opens_with_balance_brought_forward(): void
    {
        $product = $this->s->products[0];
        $grn  = $this->s->receiveStock([[$product, 10, 10.00]], '2026-01-10');
        $bill = $this->s->billGrn($grn, '2026-01-15', 0);  // 100.00 — BEFORE the range

        $rows = app(SupplierBalanceService::class)->statement($this->s->companyId, (int) $bill->partner_id, null, '2026-02-01', '2026-02-28');

        $this->assertCount(1, $rows, 'H11: with no movement in the range, the statement still shows what is owed.');
        $this->assertTrue($rows[0]['is_opening']);
        $this->assertMoneyEquals((string) $bill->fresh()->bill_value_functional, $rows[0]['end_balance']);
    }

    public function test_statement_is_empty_when_nothing_is_owed_and_nothing_moved(): void
    {
        $product = $this->s->products[0];
        $this->s->receiveStock([[$product, 10, 10.00]], '2026-01-10');
        $so = $this->s->createSalesOrder([[$product, 1, 50.00]], '2026-02-01');

        $rows = app(CustomerBalanceService::class)->statement($this->s->companyId, (int) $so->partner_id, null, '2026-03-01', '2026-03-31');

        $this->assertSame([], $rows);
    }
}
