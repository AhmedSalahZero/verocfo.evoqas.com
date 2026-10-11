<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM6M7M8M9M10Test
//
//  Regression tests for Audit items M-6 to M-10 (Oct 2026):
//    M6  a month can be reopened while LATER months are still open;
//        only a later CLOSED month blocks it (newest closed first);
//    M7  an account that still carries a balance cannot be deactivated;
//    M8  Gross Profit = Sales Revenue − COGS: other income and FX gains
//        sit below Operating Profit, and Profit Before Tax is unchanged;
//    M9  a credit note is limited to the invoice line it credits, and a
//        posted credit note can be reversed;
//    M10 a multi-line foreign-rate document balances to the cent.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\CreditNote;
use App\Models\CustomerInvoice;
use App\Services\ChartOfAccountService;
use App\Services\Accounting\CreditNoteService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM6M7M8M9M10Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(3);
        $this->s->receiveStock([
            [$this->s->products[0], 50, 10.00],
            [$this->s->products[1], 50, 10.00],
            [$this->s->products[2], 50, 10.00],
        ], '2026-01-10');
    }

    // ── M6 ────────────────────────────────────────────────────────

    public function test_a_month_reopens_while_later_months_are_still_open(): void
    {
        [$jan, $feb] = $this->closeFirstMonths(2);
        $fy = app(FiscalYearService::class);

        // January is blocked: February (later) is still CLOSED.
        try {
            $fy->reopenPeriod($jan->fresh(), $this->actor, 'Correcting a January entry');
            $this->fail('M6: January cannot be reopened while February is closed.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reverse sequence', $e->getMessage());
        }

        // February is the newest closed month; March onward are open — that must NOT block it.
        $reopened = $fy->reopenPeriod($feb->fresh(), $this->actor, 'Correcting a February entry');
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $reopened->status);
        $this->assertTrue($jan->fresh()->canReopen(), 'M6: with February open again, January is the newest closed month.');
        $this->assertFalse($feb->fresh()->canReopen(), 'M6: an open month cannot be reopened.');
    }

    // ── M7 ────────────────────────────────────────────────────────

    public function test_an_account_with_a_balance_cannot_be_deactivated(): void
    {
        $ar = $this->s->coaId('133001');
        ChartOfAccount::withoutGlobalScopes()->where('id', $ar)->update(['is_system' => false]);

        $this->egpInvoice(2, 50.00); // puts a balance on 133001

        try {
            app(ChartOfAccountService::class)->toggleActive(ChartOfAccount::withoutGlobalScopes()->findOrFail($ar), $this->actor);
            $this->fail('M7: deactivating an account with a balance must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('still has a balance', $e->getMessage());
        }
        $this->assertTrue((bool) ChartOfAccount::withoutGlobalScopes()->find($ar)->is_active);

        // A zero-balance account can be switched off, and back on.
        $quiet = $this->s->coaId('543');
        ChartOfAccount::withoutGlobalScopes()->where('id', $quiet)->update(['is_system' => false]);
        $svc = app(ChartOfAccountService::class);
        $off = $svc->toggleActive(ChartOfAccount::withoutGlobalScopes()->findOrFail($quiet), $this->actor);
        $this->assertFalse((bool) $off->is_active);
        $on = $svc->toggleActive($off->fresh(), $this->actor);
        $this->assertTrue((bool) $on->is_active);
    }

    // ── M8 ────────────────────────────────────────────────────────

    public function test_gross_profit_excludes_other_income_and_fx_gains(): void
    {
        $this->egpInvoice(2, 50.00); // sales 100, COGS 2 × 10 = 20

        // 500 of interest income (423) — NOT sales.
        app(JournalEntryService::class)->post(
            companyId: $this->s->companyId,
            entryDate: '2026-02-10',
            lines: [
                ['chart_of_account_id' => $this->s->coaId('133001'), 'debit' => '500.00', 'credit' => '0', 'description' => 'interest'],
                ['chart_of_account_id' => $this->s->coaId('423'), 'debit' => '0', 'credit' => '500.00', 'description' => 'interest'],
            ],
            sourceType: 'manual_journal_entry',
            sourceId: null,
            narration: 'M8 other income',
            actor: $this->actor,
        );

        $periods = [['key' => 'all', 'label' => 'All', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']];
        $r = app(IncomeStatementService::class)->build($this->s->companyId, $periods);

        $this->assertSame('100.00', $r['sales_revenue']['totals']['all']);
        $this->assertSame('500.00', $r['other_income']['totals']['all']);
        $this->assertSame('600.00', $r['revenue']['totals']['all']);
        $this->assertSame('80.00', $r['gross_profit']['all'], 'M8: gross profit = 100 sales − 20 cost, interest income excluded.');
        $this->assertSame('80.00', $r['operating_profit']['all'], 'M8: operating profit stops before other income.');
        $this->assertSame('580.00', $r['profit_before_tax']['all'], 'M8: the bottom line still includes the interest.');
        $this->assertSame('580.00', $r['net_income']['all']);
    }

    // ── M9 ────────────────────────────────────────────────────────

    public function test_a_credit_cannot_exceed_the_invoice_line(): void
    {
        $invoice = $this->egpInvoice(2, 50.00); // 100.00
        $line    = $invoice->lines()->first();

        $this->expectCreditRefused($invoice, $line, 3, 50, 'more than the');   // quantity
        $this->expectCreditRefused($invoice, $line, 1, 60, 'unit price');       // price
        $this->expectCreditRefused($invoice, $line, 1, 50, 'does not belong', 999999); // another invoice's line

        $svc = app(CreditNoteService::class);
        $svc->post($this->credit($invoice, $line, 2, 40), $this->actor);        // 2 × 40 = 80 of 100 used

        $this->expectCreditRefused($invoice, $line, 1, 30, 'would exceed');     // 80 + 30 > 100
        $svc->create($this->s->companyId, $this->payload($invoice, $line, 1, 20), $this->actor); // 80 + 20 = 100 is fine
        $this->assertTrue(true);
    }

    public function test_a_posted_credit_note_can_be_reversed(): void
    {
        $invoice = $this->egpInvoice(2, 50.00);
        $before  = (string) $invoice->fresh()->amount_outstanding;
        $svc     = app(CreditNoteService::class);

        $cn = $svc->post($this->credit($invoice, $invoice->lines()->first(), 1, 20), $this->actor);
        $this->assertSame(bcsub($before, '20.00', 2), (string) $invoice->fresh()->amount_outstanding);

        try {
            $svc->reverse($cn, $this->actor, 'abc');
            $this->fail('M9: a reason is required.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $reversed = $svc->reverse($cn->fresh(), $this->actor, 'Raised on the wrong invoice');
        $this->assertSame(CreditNote::STATUS_REVERSED, $reversed->status);
        $this->assertSame($before, (string) $invoice->fresh()->amount_outstanding, 'M9: the invoice owes the full amount again.');
        $this->assertSame('0.00', (string) $invoice->fresh()->amount_credited);

        // The credit it used is free again, and a reversed note cannot be reversed twice.
        $svc->create($this->s->companyId, $this->payload($invoice, $invoice->lines()->first(), 2, 50), $this->actor);
        $this->expectException(\DomainException::class);
        $svc->reverse($reversed->fresh(), $this->actor, 'Second attempt');
    }

    // ── M10 ───────────────────────────────────────────────────────

    public function test_document_functional_total_equals_the_sum_of_its_lines(): void
    {
        // Sanity check on the arithmetic rule (an EGP document converts
        // at 1.0, so this guards the rule rather than the rounding gap
        // itself): the stored functional total is the sum of the
        // per-line converted figures, which is what the ledger carries.
        $so = $this->s->createSalesOrder([[$this->s->products[0], 1, 3.33], [$this->s->products[1], 1, 3.33], [$this->s->products[2], 1, 3.33]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoice = $this->s->invoice($so, '2026-02-05', 14)->fresh('lines');

        $sum = '0';
        foreach ($invoice->lines as $l) {
            $sum = bcadd($sum, bcadd(bcmul((string) $l->line_subtotal, (string) $invoice->exchange_rate, 2), bcmul((string) $l->vat_amount, (string) $invoice->exchange_rate, 2), 2), 2);
        }
        $this->assertSame($sum, (string) $invoice->invoice_value_functional);
        $this->assertSame($sum, (string) $invoice->amount_outstanding);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function egpInvoice(int $qty, float $price): CustomerInvoice
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], $qty, $price]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');

        return $this->s->invoice($so, '2026-02-05', 0);
    }

    private function payload(object $invoice, object $line, $qty, $price, ?int $lineId = null): array
    {
        return [
            'customer_invoice_id' => $invoice->id, 'credit_date' => '2026-02-20', 'credit_reason' => 'Test',
            'lines' => [[
                'customer_invoice_line_id' => $lineId ?? $line->id, 'description' => 'Credit',
                'quantity' => $qty, 'unit_price' => $price, 'vat_rate' => 0,
            ]],
        ];
    }

    private function credit(object $invoice, object $line, $qty, $price): CreditNote
    {
        return app(CreditNoteService::class)->create($this->s->companyId, $this->payload($invoice, $line, $qty, $price), $this->actor);
    }

    private function expectCreditRefused(object $invoice, object $line, $qty, $price, string $needle, ?int $lineId = null): void
    {
        try {
            app(CreditNoteService::class)->create($this->s->companyId, $this->payload($invoice, $line, $qty, $price, $lineId), $this->actor);
            $this->fail("M9: a credit of {$qty} × {$price} should have been refused.");
        } catch (\DomainException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** Closes the first $count months of the company's first fiscal year, oldest first. */
    private function closeFirstMonths(int $count): array
    {
        $periods = AccountingPeriod::withoutGlobalScopes()->where('company_id', $this->s->companyId)
            ->orderBy('start_date')->limit($count)->get();
        foreach ($periods as $p) {
            $p->update(['status' => AccountingPeriod::STATUS_CLOSED]);
        }

        return $periods->all();
    }
}
