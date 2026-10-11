<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM13WacTest
//
//  A receipt dated before an issue that is already booked used to
//  be refused. It is now accepted: the later issue is re-costed on
//  the stock subledger, and the difference is a new journal. The
//  original issue journal is not rewritten.
//
//  Hand-worked:
//    10 Jan  10 units @ 10.00  inventory 100.00
//    20 Jan  issue 4 @ 10.00   COGS 40.00, inventory 60.00
//    15 Jan  10 units @ 20.00  inserted between them
//    correct average at the issue = (100 + 200) / 20 = 15.00
//    issue should have been 60.00, so the new journal is
//    Dr COGS 20.00 / Cr inventory 20.00
//    ending inventory 240.00, COGS 60.00, qty 16, WAC 15.0000
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM13WacTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap();
    }

    public function test_backdated_receipt_revalues_later_issues_without_rewriting_their_journals(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 10, 10.00]], '2026-01-10');

        $so = $this->scenario->createSalesOrder([[$product, 4, 25.00]], '2026-01-20');
        $dn = $this->scenario->deliver($so, '2026-01-20');

        $original = JournalEntryLine::where('journal_entry_id', $dn->inventory_journal_entry_id)
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [(string) $line->debit, (string) $line->credit])
            ->all();

        $this->scenario->receiveStock([[$product, 10, 20.00]], '2026-01-15');

        $after = JournalEntryLine::where('journal_entry_id', $dn->inventory_journal_entry_id)
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [(string) $line->debit, (string) $line->credit])
            ->all();

        $this->assertSame($original, $after, 'The delivery journal must stay at the cost it was posted with.');

        $adjustment = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'wac_revaluation')
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertMoneyEquals('20.00', (string) $adjustment->lines()->where('chart_of_account_id', $product->cogs_account_id)->value('debit'));
        $this->assertMoneyEquals('20.00', (string) $adjustment->lines()->where('chart_of_account_id', $product->inventory_account_id)->value('credit'));

        $issue = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_ISSUE)
            ->first();

        $this->assertMoneyEquals('60.00', (string) $issue->wac_total_value);
        $this->assertUnitCostEquals('15.0000', (string) $issue->wac_unit_cost);

        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->first();

        $this->assertQuantityEquals('16', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('240.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals('15.0000', (string) $balance->wac_unit_cost);

        $this->assertMoneyEquals('240.00', $this->glBalanceById($this->scenario->companyId, $product->inventory_account_id));
        $this->assertMoneyEquals('60.00', $this->glBalanceById($this->scenario->companyId, $product->cogs_account_id));
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    public function test_receipt_with_no_later_issue_does_not_post_a_revaluation(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 10, 10.00]], '2026-01-10');
        $this->scenario->receiveStock([[$product, 10, 20.00]], '2026-01-20');

        $this->assertSame(
            0,
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('source_type', 'wac_revaluation')
                ->count()
        );

        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->first();

        $this->assertQuantityEquals('20', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('300.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals('15.0000', (string) $balance->wac_unit_cost);
    }
}
