<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PurchaseOrderController
//
//  HTTP layer for Purchase Order master data. Zero business logic —
//  delegated to PurchaseOrderService. Mirrors SalesOrderController's
//  pattern closely.
//
//  Lives under the Accounting pillar (confirmed by Mahmoud — NOT
//  under Projects, despite the optional project link), gated by
//  Company::hasInventory() in the sidebar, same as Inventory /
//  Internal Transfers.
//
//  allowProjectLink is passed to the Vue form explicitly rather than
//  inferring it from an empty `projects` array — an empty array
//  could just as easily mean "no active projects exist yet" for a
//  company that's otherwise allowed to link one. Gated on
//  Company::hasMixedInvoices() — reused deliberately (see migration
//  000073 header) since its documented condition, "Service with
//  Inventory", is exactly this gate.
//
//  Routes (under auth + active.company):
//    GET    /accounting/purchase-orders                    → index()
//    GET    /accounting/purchase-orders/create             → create()
//    POST   /accounting/purchase-orders                    → store()
//    GET    /accounting/purchase-orders/{po}/edit           → edit()
//    PUT    /accounting/purchase-orders/{po}                → update()
//    PATCH  /accounting/purchase-orders/{po}/activate       → activate()
//    PATCH  /accounting/purchase-orders/{po}/close          → close()
//    PATCH  /accounting/purchase-orders/{po}/force-close    → forceClose()
//
//  @layer    Controller
//  @depends  PurchaseOrderService, PurchaseOrder, PurchaseOrderRequest,
//            ForceClosePurchaseOrderRequest
//
//  v1.1 — store()/update()/forceClose() now use dedicated Form Request
//    classes (PurchaseOrderRequest, ForceClosePurchaseOrderRequest)
//    instead of an inline private rules() method — matches the house
//    style used by PartnerRequest/ProductRequest/etc. and the Projects
//    module's SalesOrderRequest. No validation rule changed.
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ForceClosePurchaseOrderRequest;
use App\Http\Requests\Accounting\PurchaseOrderRequest;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\UnitOfMeasure;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/PurchaseOrders/Index', [
            'purchaseOrders' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id', 'project_id')),
            'filters'        => $request->only('search', 'status', 'partner_id', 'project_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);
        $company   = $request->user()->activeCompany ?? \App\Models\Company::find($companyId);

        return Inertia::render('Accounting/PurchaseOrders/Form', [
            'purchaseOrder'    => null,
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'payment_term_id']),
            'allowProjectLink' => $company?->hasMixedInvoices() ?? false,
            'projects'         => Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en', 'partner_id']),
            'currencies'       => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'paymentTerms'     => PaymentTerm::forCompany($companyId)->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'products'         => $this->productsForForm($companyId),
            'uoms'             => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function store(PurchaseOrderRequest $request)
    {
        try {
            $po = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.purchase-orders.index')->with('success', "Purchase Order [{$po->po_code}] created successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder)
    {
        $companyId = $this->companyId($request);
        $company   = $request->user()->activeCompany ?? \App\Models\Company::find($companyId);

        return Inertia::render('Accounting/PurchaseOrders/Form', [
            'purchaseOrder'    => $purchaseOrder->load(['partner:id,name_en', 'project:id,name_en,contract_code', 'currency:id,code', 'lines.product:id,name_en', 'lines.uom:id,code']),
            'issuanceDateLocked' => $this->service->isIssuanceDateLocked($purchaseOrder),
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'payment_term_id']),
            'allowProjectLink' => $company?->hasMixedInvoices() ?? false,
            'projects'         => Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en', 'partner_id']),
            'currencies'       => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'paymentTerms'     => PaymentTerm::forCompany($companyId)->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'products'         => $this->productsForForm($companyId),
            'uoms'             => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validated();
        try {
            $this->service->update($this->companyId($request), $purchaseOrder, $data, $request->user());
            return redirect()->route('accounting.purchase-orders.index')->with('success', 'Purchase Order updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function activate(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->service->activate($purchaseOrder, $request->user());
        return back()->with('success', "Purchase Order [{$purchaseOrder->po_code}] activated.");
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->service->close($purchaseOrder, $request->user());
        return back()->with('success', "Purchase Order [{$purchaseOrder->po_code}] closed.");
    }

    public function forceClose(ForceClosePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validated();
        $this->service->forceClose($purchaseOrder, $data['closure_reason'], $request->user());
        return back()->with('success', "Purchase Order [{$purchaseOrder->po_code}] closed early.");
    }

    // activeCurrencies() removed (July 2026 audit) — consolidated into
    // CurrencyService::activeCurrenciesOrdered().

    /**
     * Products for the PO line-item dropdown, each carrying a
     * `purchase_uom_id` — the UOM flagged `use_for_purchase` on that
     * product (e.g. "Carton"), falling back to the product's primary
     * storage UOM only if no purchase-specific UOM was set up.
     *
     * Fixed July 2026: the form previously only ever knew the
     * product's primary_uom_id (the internal stock-counting unit,
     * e.g. "Unit") and auto-filled with that — ignoring a supplier's
     * actual purchase unit entirely (e.g. buying by the Carton of
     * 100). The user can still override the auto-filled value on any
     * line before saving.
     */
    private function productsForForm(int $companyId)
    {
        return Product::active()
            ->with(['uomLines' => fn ($q) => $q->where('use_for_purchase', true)])
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id'])
            ->map(function (Product $product) {
                $product->purchase_uom_id = $product->uomLines->first()->uom_id ?? $product->primary_uom_id;
                return $product;
            })
            ->makeHidden('uomLines');
    }
}
