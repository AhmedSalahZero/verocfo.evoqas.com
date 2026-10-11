<?php

namespace Tests\Erp\Support;

// ══════════════════════════════════════════════════════════════════
//  ErpScenario
//
//  Builds a real, fully-provisioned company for a test to act on,
//  and drives complete business cycles through the SAME services the
//  controllers call — never by inserting rows directly.
//
//  This is the deliberate distinction between this class and the
//  database factories a typical Laravel suite would use. Inserting a
//  stock_movements row with a factory would prove nothing about this
//  system: the entire question under audit is whether posting a
//  Goods Receipt Note moves the weighted-average cost, the location
//  balance, the pooled balance AND the general ledger consistently.
//  So every step here goes through PurchaseOrderService,
//  GoodsReceiptNoteService, DeliveryNoteService and friends, in the
//  create → activate → post sequence the UI itself performs.
//
//  The call shapes below were taken from the demo-company console
//  commands (SeedDemoTradingCompany in particular), which are the
//  repository's own authoritative examples of how these services are
//  meant to be driven.
//
//  Nothing here asserts. Assertions belong in the tests; this class
//  only arranges. It also never swallows exceptions — unlike the demo
//  seeders, whose per-block try/catch is exactly why they cannot
//  serve as a test harness (see AUDIT finding SEED-001).
//
//  @layer    Test Support
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\InventoryLocation;
use App\Models\LeaveType;
use App\Models\Partner;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\LeaveTypeService;
use App\Services\ChartOfAccountService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\CompanyService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\Projects\SalesOrderService;

class ErpScenario
{
    public Company $company;
    public int $companyId;
    public int $masterLocationId;
    public int $currencyId;
    public int $paymentTermId;
    public int $unitId;

    /** @var array<int, Product> */
    public array $products = [];
    /** @var array<int, Partner> */
    public array $customers = [];
    /** @var array<int, Partner> */
    public array $suppliers = [];
    /** @var array<int, Partner> */
    public array $employees = [];

    public function __construct(private User $actor)
    {
    }

    // ── Arrange: a complete company ───────────────────────────────

    public function createCompany(array $overrides = []): self
    {
        $this->company = app(CompanyService::class)->create(array_merge([
            'name_en'                 => 'ERP Suite Co. '.bin2hex(random_bytes(4)),
            'name_ar'                 => 'شركة الاختبار',
            'company_type'            => ['Trading'],
            'grn_policy'              => 'po_required',
            'base_currency'           => 'EGP',
            'fiscal_year_start_month' => 1,
            'opening_balance_date'    => '2026-01-01',
            'subscription_expires_at' => '2035-12-31',
            'country'                 => 'Egypt',
            'subscription_tier'       => 'Enterprise',
            'is_active'               => true,
        ], $overrides), $this->actor);

        $this->companyId = $this->company->id;

        $this->masterLocationId = (int) InventoryLocation::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('type', InventoryLocation::TYPE_MASTER)
            ->value('id');

        $this->currencyId = (int) Currency::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', 'EGP')->value('id');

        $this->paymentTermId = (int) PaymentTerm::withoutGlobalScopes()
            ->whereNull('company_id')->orderBy('id')->value('id');

        $this->unitId = (int) UnitOfMeasure::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', 'UNIT')->value('id');

        return $this;
    }

    /** An extra warehouse, for transfer / multi-location tests. */
    public function createLocation(string $name): InventoryLocation
    {
        return InventoryLocation::create([
            'company_id' => $this->companyId,
            'code'       => 'LOC'.substr(bin2hex(random_bytes(3)), 0, 5),
            'name_en'    => $name,
            'name_ar'    => $name,
            'type'       => InventoryLocation::TYPE_WAREHOUSE,
            'is_active'  => true,
        ]);
    }

    public function createProducts(int $count = 2, string $categoryName = 'Test Category'): self
    {
        $category = app(ProductCategoryService::class)
            ->create($this->companyId, ['name_en' => $categoryName], $this->actor);

        for ($i = 0; $i < $count; $i++) {
            $this->products[] = app(ProductService::class)->create($this->companyId, [
                'use_auto_code'  => true,
                'name_en'        => $categoryName.' Product '.($i + 1),
                'category_id'    => $category->id,
                'usage_types'    => [Product::TYPE_SALEABLE],
                'primary_uom_id' => $this->unitId,
                'reorder_level'  => 10,
                'reorder_qty'    => 50,
            ], $this->actor);
        }

        return $this;
    }

    public function createPartners(): self
    {
        $this->customers[] = app(PartnerService::class)->create($this->companyId, [
            'name_en'            => 'Test Customer',
            'types'              => ['customer'],
            'credit_limit'       => 10_000_000,
            'collection_term_id' => $this->paymentTermId,
        ], $this->actor);

        $this->suppliers[] = app(PartnerService::class)->create($this->companyId, [
            'name_en'         => 'Test Supplier',
            'types'           => ['supplier'],
            'payment_term_id' => $this->paymentTermId,
        ], $this->actor);

        return $this;
    }

    /** The usual arrangement: company + products + partners. */
    public function bootstrap(int $productCount = 2): self
    {
        return $this->createCompany()
            ->createProducts($productCount)
            ->createPartners();
    }

    // ── Act: purchase cycle (PO → GRN → optional Supplier Bill) ───

    /**
     * Receives stock at a known unit cost. Returns the posted GRN.
     *
     * $items: [[Product, quantity, unitCost], ...]
     */
    public function receiveStock(array $items, string $date = '2026-01-01', ?int $locationId = null): object
    {
        $supplier = $this->suppliers[0];

        $poLines = [];
        foreach ($items as [$product, $qty, $cost]) {
            $poLines[] = [
                'product_id'  => $product->id,
                'description' => $product->name_en,
                'quantity'    => $qty,
                'uom_id'      => $product->primary_uom_id,
                'unit_price'  => $cost,
            ];
        }

        $po = app(PurchaseOrderService::class)->create($this->companyId, [
            'partner_id'      => $supplier->id,
            'subject'         => 'Test purchase',
            'currency_id'     => $this->currencyId,
            'exchange_rate'   => 1,
            'issuance_date'   => $date,
            'start_date'      => $date,
            'end_date'        => $date,
            'payment_term_id' => $this->paymentTermId,
            'lines'           => $poLines,
        ], $this->actor);

        app(PurchaseOrderService::class)->activate($po, $this->actor);

        $grnLines = [];
        foreach ($po->lines as $line) {
            $grnLines[] = [
                'purchase_order_line_id' => $line->id,
                'product_id'             => $line->product_id,
                'quantity'               => $line->quantity,
                'uom_id'                 => $line->uom_id,
                'unit_cost'              => $line->unit_price,
            ];
        }

        $grn = app(GoodsReceiptNoteService::class)->create($this->companyId, [
            'purchase_order_id'     => $po->id,
            'partner_id'            => $supplier->id,
            'inventory_location_id' => $locationId ?? $this->masterLocationId,
            'received_date'         => $date,
            'lines'                 => $grnLines,
        ], $this->actor);

        return app(GoodsReceiptNoteService::class)->post($grn, $this->actor);
    }

    /** Bills a posted GRN, so GRNI clears and AP is raised. */
    public function billGrn(object $grn, string $date = '2026-01-01', float $vatRate = 14, float $whtRate = 0): object
    {
        $billLines = [];
        foreach ($grn->lines as $line) {
            $billLines[] = [
                'goods_receipt_note_line_id' => $line->id,
                'product_id'                 => $line->product_id,
                'description'                => $line->product?->name_en ?? 'Goods',
                'quantity'                   => $line->quantity,
                'uom_id'                     => $line->uom_id,
                'unit_price'                 => $line->unit_cost,
                'vat_rate'                   => $vatRate,
                'is_deductible'              => true,
                'withholding_tax_rate'       => $whtRate,
            ];
        }

        $bill = app(SupplierBillService::class)->create($this->companyId, [
            'bill_type'               => 'goods',
            'partner_id'              => $grn->partner_id,
            'purchase_order_id'       => $grn->purchase_order_id,
            'supplier_invoice_number' => 'SUP-'.bin2hex(random_bytes(4)),
            'supplier_invoice_date'   => $date,
            'bill_date'               => $date,
            'currency_id'             => $this->currencyId,
            'payment_term_id'         => $this->paymentTermId,
            'lines'                   => $billLines,
        ], $this->actor);

        return app(SupplierBillService::class)->post($bill, $this->actor);
    }

    // ── Act: sales cycle (SO → Delivery Note → Customer Invoice) ──

    /** $items: [[Product, quantity, unitPrice], ...] */
    public function createSalesOrder(array $items, string $date = '2026-02-01'): object
    {
        $lines = [];
        foreach ($items as [$product, $qty, $price]) {
            $lines[] = [
                'product_id'         => $product->id,
                'description'        => $product->name_en,
                'quantity'           => $qty,
                'uom_id'             => $product->primary_uom_id,
                'unit_price'         => $price,
                'revenue_account_id' => $product->revenue_account_id,
            ];
        }

        $so = app(SalesOrderService::class)->create($this->companyId, [
            'partner_id'      => $this->customers[0]->id,
            'subject'         => 'Test sales order',
            'currency_id'     => $this->currencyId,
            'exchange_rate'   => 1,
            'issuance_date'   => $date,
            'start_date'      => $date,
            'end_date'        => $date,
            'payment_term_id' => $this->paymentTermId,
            'lines'           => $lines,
        ], $this->actor);

        app(SalesOrderService::class)->activate($so, $this->actor);

        return $so->fresh('lines');
    }

    public function deliver(object $so, string $date = '2026-02-05', ?int $locationId = null): object
    {
        $dnLines = [];
        foreach ($so->lines as $line) {
            $dnLines[] = [
                'sales_order_line_id' => $line->id,
                'location_id'         => $locationId ?? $this->masterLocationId,
                'quantity'            => $line->quantity,
                'uom_id'              => $line->uom_id,
            ];
        }

        $dn = app(DeliveryNoteService::class)->create($this->companyId, [
            'sales_order_id' => $so->id,
            'partner_id'     => $so->partner_id,
            'delivery_date'  => $date,
            'lines'          => $dnLines,
        ], $this->actor);

        return app(DeliveryNoteService::class)->post($dn, $this->actor);
    }

    public function invoice(object $so, string $date = '2026-02-05', float $vatRate = 14): object
    {
        $ciLines = [];
        foreach ($so->lines as $line) {
            $ciLines[] = [
                'sales_order_line_id' => $line->id,
                'product_id'          => $line->product_id,
                'description'         => $line->description,
                'quantity'            => $line->quantity,
                'uom_id'              => $line->uom_id,
                'unit_price'          => $line->unit_price,
                'vat_rate'            => $vatRate,
            ];
        }

        $invoice = app(CustomerInvoiceService::class)->create($this->companyId, [
            'sales_order_id'  => $so->id,
            'partner_id'      => $so->partner_id,
            'invoice_date'    => $date,
            'currency_id'     => $this->currencyId,
            'payment_term_id' => $this->paymentTermId,
            'lines'           => $ciLines,
        ], $this->actor);

        return app(CustomerInvoiceService::class)->post($invoice, $this->actor);
    }

    // ── Arrange: HR ───────────────────────────────────────────────

    /**
     * An employee-type Partner. Leave requests and expense claims are
     * both raised against one of these, and both services refuse a
     * partner that is not typed 'employee'.
     */
    public function createEmployee(string $name = 'Test Employee'): Partner
    {
        $employee = app(PartnerService::class)->create($this->companyId, [
            'name_en' => $name,
            'types'   => ['employee'],
        ], $this->actor);

        $this->employees[] = $employee;

        return $employee;
    }

    /**
     * $entitlementDays = null creates an untracked type (no allowance
     * to consume); a number creates a balance-tracked one.
     */
    public function createLeaveType(
        string $name = 'Annual Leave',
        bool $isPaid = true,
        ?float $entitlementDays = 21
    ): LeaveType {
        return app(LeaveTypeService::class)->create($this->companyId, [
            'name_en'                 => $name,
            'is_paid'                 => $isPaid,
            'annual_entitlement_days' => $entitlementDays,
        ], $this->actor);
    }

    /** A postable expense account for expense-claim lines to hit. */
    public function expenseAccountId(string $code = '543'): int
    {
        return $this->coaId($code);
    }

    // ── Arrange: treasury ─────────────────────────────────────────

    /**
     * A postable cash-on-hand leaf under 131 Cash. The seeded Chart of
     * Accounts ships 131 as a non-postable group, exactly as it does
     * for 132 Banks — a company creates its own safes underneath.
     * Mirrors SeedDemoTradingCompany::createTreasury().
     */
    public function createCashSafe(string $name = 'Main Safe'): int
    {
        return app(ChartOfAccountService::class)->create($this->companyId, [
            'parent_id'     => $this->coaId('131'),
            'use_auto_code' => true,
            'name_en'       => $name,
        ], $this->actor)->id;
    }

    /** A bank with one current account, each wired to its own COA leaf. */
    public function createBankAccount(string $bankName = 'Test Bank'): BankAccount
    {
        $bank = app(BankService::class)->createBank($this->companyId, [
            'name_en'   => $bankName,
            'is_active' => true,
        ], $this->actor);

        $coaLeaf = app(ChartOfAccountService::class)->create($this->companyId, [
            'parent_id'     => $this->coaId('132'),
            'use_auto_code' => true,
            'name_en'       => $bankName.' — Current Account',
        ], $this->actor);

        return app(BankService::class)->createBankAccount($this->companyId, $bank, [
            'account_name'   => $bankName.' — Current Account',
            'account_number' => '100'.random_int(1000000, 9999999),
            'account_type'   => 'current',
            'currency_id'    => $this->currencyId,
            'coa_account_id' => $coaLeaf->id,
            'is_active'      => true,
        ], $this->actor);
    }

    // ── Read helpers ──────────────────────────────────────────────

    public function coaId(string $code): int
    {
        return (int) ChartOfAccount::withoutGlobalScopes()
            ->forCompany($this->companyId)->where('code', $code)->value('id');
    }
}
