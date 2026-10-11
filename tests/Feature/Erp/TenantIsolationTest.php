<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  TenantIsolationTest
//
//  Vero CFO is multi-tenant: many companies share one database and
//  one shared, system-level Chart of Accounts. The boundary between
//  them is therefore enforced in two quite different places, and
//  both need testing:
//
//   1. CompanyScope, a global Eloquent scope, which filters reads —
//      but only when a user is authenticated AND 'currentCompanyId'
//      is bound. It is deliberately inert in console and queue
//      contexts (see that class's header).
//
//   2. Explicit guards in the services themselves — the
//      assertXBelongsToCompany() family added to StockLedgerService
//      in the July 2026 audit (Building Bible Rule 2.5), which
//      re-check ownership of raw ids that arrive from a request even
//      if the calling Form Request forgot to.
//
//  These tests exercise (2) directly, since that is the layer that
//  actually protects the ledger regardless of context.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class TenantIsolationTest extends ErpTestCase
{
    private ErpScenario $alpha;
    private ErpScenario $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
        $this->beta  = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    /** A company may not receive stock into another company's warehouse. */
    public function test_stock_cannot_be_received_into_another_companys_location(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/location does not belong to this company/i');

        app(StockLedgerService::class)->receive(
            companyId: $this->alpha->companyId,
            product: $this->alpha->products[0],
            inventoryLocationId: $this->beta->masterLocationId,
            quantity: '10',
            unitCost: '5.00',
            actor: $this->actor,
        );
    }

    /** The same guard on the way out. */
    public function test_stock_cannot_be_issued_from_another_companys_location(): void
    {
        $this->alpha->receiveStock([[$this->alpha->products[0], 100, 5.00]], '2026-01-01');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/location does not belong to this company/i');

        app(StockLedgerService::class)->issue(
            companyId: $this->alpha->companyId,
            product: $this->alpha->products[0],
            inventoryLocationId: $this->beta->masterLocationId,
            quantity: '10',
            actor: $this->actor,
        );
    }

    /** A transfer may not be used to move stock across the boundary. */
    public function test_stock_cannot_be_transferred_into_another_companys_location(): void
    {
        $this->alpha->receiveStock([[$this->alpha->products[0], 100, 5.00]], '2026-01-01');

        $this->expectException(\DomainException::class);

        app(StockLedgerService::class)->transfer(
            companyId: $this->alpha->companyId,
            product: $this->alpha->products[0],
            fromLocationId: $this->alpha->masterLocationId,
            toLocationId: $this->beta->masterLocationId,
            quantity: '10',
            actor: $this->actor,
        );
    }

    /** A rejected cross-company attempt must leave no trace at all. */
    public function test_a_rejected_cross_company_write_leaves_no_partial_state(): void
    {
        $movementsBefore = StockMovement::withoutGlobalScopes()->count();
        $balancesBefore  = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->beta->companyId)->count();

        try {
            app(StockLedgerService::class)->receive(
                companyId: $this->alpha->companyId,
                product: $this->alpha->products[0],
                inventoryLocationId: $this->beta->masterLocationId,
                quantity: '10',
                unitCost: '5.00',
                actor: $this->actor,
            );
            $this->fail('The cross-company receipt should have been refused.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame(
            $movementsBefore,
            StockMovement::withoutGlobalScopes()->count(),
            'A refused write must not create a stock movement.'
        );
        $this->assertSame(
            $balancesBefore,
            ProductStockBalance::withoutGlobalScopes()
                ->where('company_id', $this->beta->companyId)->count(),
            'A refused write must not create a balance row for the other company.'
        );
    }

    /**
     * Trading in one company must leave the other company's ledger,
     * stock and balances completely untouched — the practical
     * statement of tenant isolation.
     */
    public function test_activity_in_one_company_does_not_touch_the_other(): void
    {
        $betaEntriesBefore = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->beta->companyId)->count();

        $product = $this->alpha->products[0];
        $this->alpha->receiveStock([[$product, 100, 12.00]], '2026-01-01');
        $so = $this->alpha->createSalesOrder([[$product, 40, 30.00]], '2026-02-01');
        $this->alpha->deliver($so, '2026-02-05');
        $this->alpha->invoice($so, '2026-02-05');

        $this->assertSame(
            $betaEntriesBefore,
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->beta->companyId)->count(),
            'Posting in one company must not create entries in another.'
        );

        $this->assertSame(
            0,
            StockMovement::withoutGlobalScopes()
                ->where('company_id', $this->beta->companyId)->count(),
            'The other company must have no stock movements.'
        );

        $this->assertLedgerBalanced($this->alpha->companyId);
        $this->assertLedgerBalanced($this->beta->companyId);
    }

    /**
     * Journal entry lines must never reference an account belonging
     * to a different company, and must always carry the same
     * company_id as their parent entry.
     */
    public function test_journal_lines_never_cross_the_company_boundary(): void
    {
        $product = $this->alpha->products[0];
        $this->alpha->receiveStock([[$product, 100, 12.00]], '2026-01-01');
        $so = $this->alpha->createSalesOrder([[$product, 40, 30.00]], '2026-02-01');
        $this->alpha->deliver($so, '2026-02-05');
        $this->alpha->invoice($so, '2026-02-05');

        $mismatchedParent = DB::selectOne(
            'select count(*) n
               from journal_entry_lines l
               join journal_entries je on je.id = l.journal_entry_id
              where l.company_id <> je.company_id'
        )->n;

        $this->assertSame(0, (int) $mismatchedParent, 'A line must belong to its entry\'s company.');

        // Accounts are either the company's own or shared system rows
        // (company_id NULL) — never another tenant's.
        $foreignAccounts = DB::selectOne(
            'select count(*) n
               from journal_entry_lines l
               join chart_of_accounts a on a.id = l.chart_of_account_id
              where l.company_id = ?
                and a.company_id is not null
                and a.company_id <> ?',
            [$this->alpha->companyId, $this->alpha->companyId]
        )->n;

        $this->assertSame(
            0, (int) $foreignAccounts,
            'A posting must never reference another company\'s chart of accounts.'
        );
    }

    /** Each company keeps its own independent weighted-average cost. */
    public function test_each_company_keeps_its_own_weighted_average_cost(): void
    {
        $this->alpha->receiveStock([[$this->alpha->products[0], 100, 10.00]], '2026-01-01');
        $this->beta->receiveStock([[$this->beta->products[0], 100, 99.00]], '2026-01-01');

        $alphaWac = (string) ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->alpha->companyId)->value('wac_unit_cost');
        $betaWac = (string) ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->beta->companyId)->value('wac_unit_cost');

        $this->assertUnitCostEquals('10.0000', $alphaWac);
        $this->assertUnitCostEquals('99.0000', $betaWac);
    }
}
