<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  CycleContext
//
//  One company being operated by the cycle: who it is, what master
//  data it has, what documents are still open, and the cycle's own
//  parallel inventory book.
//
//  This is deliberately a mutable bag of state rather than something
//  cleverer, because that is genuinely what it models — a company
//  accumulating a history. Year two reads the state year one left
//  behind; that is the whole point.
//
//  OPEN DOCUMENT TRACKING is what lets transactions depend on each
//  other properly. A collection settles an invoice that a real sale
//  produced; a supplier payment settles a bill that a real purchase
//  produced. Without this the cycle would only ever create isolated
//  documents that happen to sit in the same database.
//
//  @layer    Console Support
//  @depends  InventoryBook
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;

class CycleContext
{
    public Company $company;
    public int $companyId;
    public string $label;

    public int $currencyId;
    public int $paymentTermId;
    public int $unitId;
    public int $masterLocationId;
    public ?int $secondLocationId = null;
    public ?int $safeAccountId = null;

    /** @var array<int, Product> */
    public array $rawMaterials = [];
    /** @var array<int, Product> */
    public array $finishedGoods = [];
    /** @var array<int, Product> */
    public array $tradeGoods = [];

    /** @var array<int, Partner> */
    public array $customers = [];
    /** @var array<int, Partner> */
    public array $suppliers = [];
    /** @var array<int, Partner> */
    public array $employees = [];
    /** @var array<int, Partner> */
    public array $shareholders = [];

    /** @var array<int, \App\Models\FinancialInstitutions\BankAccount> */
    public array $bankAccounts = [];

    /** @var array<string, int> friendly name => chart_of_accounts.id */
    public array $expenseAccounts = [];

    /** Bill of materials, keyed by finished-good product id. */
    public array $bomsByProduct = [];

    public ?int $costCenterId = null;

    public ?int $overheadPoolId = null;
    public ?int $processId = null;
    public ?int $machineId = null;
    public ?int $laborCategoryId = null;

    /** Documents still awaiting settlement — drives the payment cycles. */
    public array $openSupplierBills = [];
    public array $openCustomerInvoices = [];

    /** Leave types and claims created, for the HR scenarios. */
    public array $leaveTypes = [];

    // ── Treasury ──────────────────────────────────────────────────
    public $overdraftFacility = null;
    public $lgAgreement = null;
    public array $lgTypeIds = [];
    public $lcAgreement = null;
    public $factoringAgreement = null;
    public $leasingContract = null;

    // ── Fixed assets and payroll ──────────────────────────────────
    /** @var array<int, array{asset: mixed, from: \Carbon\Carbon}> */
    public array $fixedAssets = [];
    /** @var array<int, mixed> */
    public array $payrollProfiles = [];

    // ── Multi-currency ────────────────────────────────────────────
    public ?int $foreignCurrencyId = null;

    /** A partner that is BOTH customer and supplier — for netting. */
    public $dualRolePartner = null;

    /** A freight agent, flagged as a landing cost supplier. */
    public $landingCostSupplier = null;

    /** Counters that keep the lifecycle mix deterministic. */
    public int $chequesHandled = 0;
    public int $guaranteesHandled = 0;

    public int $factoringSettlements = 0;

    public InventoryBook $book;

    /**
     * Unit selling prices and standard costs, fixed per product at
     * setup so every expectation in the cycle is reproducible.
     *
     * @var array<int, array{cost: string, price: string}>
     */
    public array $pricing = [];

    public function __construct(public User $actor, string $label)
    {
        $this->label = $label;
        $this->book  = new InventoryBook();
    }

    /** Every product this company sells, whichever way it got them. */
    public function sellableProducts(): array
    {
        return $this->tradeGoods !== [] ? $this->tradeGoods : $this->finishedGoods;
    }

    public function cost(int $productId): string
    {
        return $this->pricing[$productId]['cost'] ?? '0.00';
    }

    public function price(int $productId): string
    {
        return $this->pricing[$productId]['price'] ?? '0.00';
    }

    /**
     * Deterministic rotation — no randomness anywhere in the cycle,
     * so the same run always produces the same history and any
     * failure can be reproduced exactly.
     */
    public function rotate(array $items, int $index)
    {
        if ($items === []) {
            return null;
        }

        return $items[$index % count($items)];
    }
}
