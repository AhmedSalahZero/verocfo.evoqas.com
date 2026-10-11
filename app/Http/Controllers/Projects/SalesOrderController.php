<?php

namespace App\Http\Controllers\Projects;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderController
//
//  HTTP layer for Sales Order master data.
//  Zero business logic — all delegated to SalesOrderService.
//
//  Routes (under auth + active.company):
//    GET    /projects/sales-orders                → index()
//    GET    /projects/sales-orders/create         → create()
//    POST   /projects/sales-orders                → store()
//    GET    /projects/sales-orders/{so}/edit      → edit()
//    PUT    /projects/sales-orders/{so}           → update()
//    PATCH  /projects/sales-orders/{so}/activate  → activate()
//    PATCH  /projects/sales-orders/{so}/close     → close()
//
//  Fix log:
//    - customers: Partner::ofType('customer') — types is a JSON array,
//      the old where('partner_type', ...) column doesn't exist.
//    - products: select 'primary_uom_id' not 'default_uom_id' — that
//      column was never named default_uom_id on the products table.
//    - uoms: select 'code' not 'symbol' — units_of_measure has no
//      symbol column. Also added UnitOfMeasure::forCompany() — the
//      old query had no company filter at all and would mix every
//      company's custom UOMs together.
//    - currencies: built via company_active_currencies pivot using
//      the same activeCurrencies() pattern as ProjectController /
//      CleanOverdraftController. Currency::forCompany() alone never
//      worked — the model's own global CompanyScope silently drops
//      every system currency (company_id = NULL) before the scope's
//      OR clause can apply.
//
//  v1.1 — store()/update() now use a dedicated SalesOrderRequest
//    Form Request class instead of an inline private rules() method
//    — matches the house style used by PartnerRequest/ProductRequest/
//    etc. in the Settings stream. No validation rule changed.
//
//  @layer    Controller
//  @depends  SalesOrderService, SalesOrder, SalesOrderRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SalesOrderRequest;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasure;
use App\Services\CurrencyService;
use App\Services\Projects\SalesOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesOrderController extends Controller
{
    public function __construct(
        private SalesOrderService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Projects/SalesOrders/Index', [
            'salesOrders' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id', 'project_id')),
            'filters'     => $request->only('search', 'status', 'partner_id', 'project_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Projects/SalesOrders/Form', [
            'salesOrder'   => null,
            'customers'    => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'     => Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en', 'partner_id']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'paymentTerms' => PaymentTerm::forCompany($companyId)->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'products'     => $this->productsForForm($companyId),
            'uoms'         => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function store(SalesOrderRequest $request)
    {
        $data = $request->validated();
        $so   = $this->service->create($this->companyId($request), $data, $request->user());
        return redirect()->route('projects.sales-orders.index')->with('success', "Sales Order [{$so->so_code}] created successfully.");
    }

    public function edit(Request $request, SalesOrder $salesOrder)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Projects/SalesOrders/Form', [
            'salesOrder'   => $salesOrder->load(['partner:id,name_en', 'project:id,name_en,contract_code', 'currency:id,code', 'lines.product:id,name_en', 'lines.uom:id,code']),
            'issuanceDateLocked' => $this->service->isIssuanceDateLocked($salesOrder),
            'customers'    => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'     => Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en', 'partner_id']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'paymentTerms' => PaymentTerm::forCompany($companyId)->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'products'     => $this->productsForForm($companyId),
            'uoms'         => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(SalesOrderRequest $request, SalesOrder $salesOrder)
    {
        $data = $request->validated();
        try {
            $this->service->update($this->companyId($request), $salesOrder, $data, $request->user());
            return redirect()->route('projects.sales-orders.index')->with('success', 'Sales Order updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function activate(Request $request, SalesOrder $salesOrder)
    {
        $this->service->activate($salesOrder, $request->user());
        return back()->with('success', "Sales Order [{$salesOrder->so_code}] activated.");
    }

    public function close(Request $request, SalesOrder $salesOrder)
    {
        $this->service->close($salesOrder, $request->user());
        return back()->with('success', "Sales Order [{$salesOrder->so_code}] closed.");
    }

    /**
     * Live lookup — every physical-goods line on this SO with its
     * Ordered / Delivered / Remaining quantity, for the "Delivered
     * Quantities" button on the Index page (Mahmoud's request).
     * Deliberately shows EVERY physical-goods line, including fully-
     * delivered ones — this is a reporting view, not the delivery-
     * creation picker (DeliveryNoteController::eligibleSoLines()),
     * which filters those out on purpose.
     */
    public function deliveredQuantities(Request $request, SalesOrder $salesOrder)
    {
        $lines = $salesOrder->lines()
            ->whereHas('product', fn ($q) => $q->where('has_stock', true))
            ->with('product:id,name_en,name_ar')
            ->get()
            ->map(fn ($line) => [
                'id'                 => $line->id,
                'product_name'       => $line->product?->name_en,
                'description'        => $line->description,
                'ordered_quantity'   => (string) $line->quantity,
                'delivered_quantity' => (string) $line->delivered_quantity,
                'remaining_quantity' => bcsub((string) $line->quantity, (string) $line->delivered_quantity, 4),
            ]);

        return response()->json(['lines' => $lines]);
    }

    // activeCurrencies() removed (July 2026 audit) — consolidated into
    // CurrencyService::activeCurrenciesOrdered().

    /**
     * Products for the SO line-item dropdown, each carrying a
     * `sales_uom_id` — the UOM flagged `use_for_sales` on that product
     * (e.g. "Bag"), falling back to the product's primary storage UOM
     * only if no sales-specific UOM was set up. Same fix and same
     * reasoning as PurchaseOrderController::productsForForm() — the
     * form previously only ever knew primary_uom_id (the internal
     * stock-counting unit) and ignored the customer-facing sales unit
     * entirely. Still fully overridable per line.
     */
    private function productsForForm(int $companyId)
    {
        return Product::active()
            ->with(['uomLines' => fn ($q) => $q->where('use_for_sales', true)])
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'revenue_account_id'])
            ->map(function (Product $product) {
                $product->sales_uom_id = $product->uomLines->first()->uom_id ?? $product->primary_uom_id;
                return $product;
            })
            ->makeHidden('uomLines');
    }
}