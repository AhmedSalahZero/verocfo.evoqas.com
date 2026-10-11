<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  ProductController
//
//  HTTP layer for Products & Services settings.
//  Zero business logic — all delegated to ProductService.
//
//  Routes:
//    GET    /settings/products               → index()
//    GET    /settings/products/create        → create()
//    POST   /settings/products               → store()
//    GET    /settings/products/{product}     → edit()
//    PUT    /settings/products/{product}     → update()
//    PATCH  /settings/products/{product}/toggle → toggleActive()
//    POST   /settings/products/prices        → addPrice()
//    GET    /settings/products/{product}/prices → prices() [JSON]
//
//  @layer    Controller
//  @depends  App\Services\ProductService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProductRequest;
use App\Models\Product;
use App\Services\CurrencyService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ProductService  $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Settings/Products/Index', [
            'products'    => $this->service->paginate($companyId, $request->only('search','category_id','nature','status')),
            'categories'  => $this->service->categoriesForDropdown($companyId),
            'filters'     => $request->only('search','category_id','nature','status'),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Settings/Products/Form', [
            'product'    => null,
            'categories' => $this->service->categoriesForDropdown($companyId),
            'uoms'       => \App\Models\UnitOfMeasure::withoutGlobalScopes()
                                ->forCompany($companyId)->active()->orderBy('type')->orderBy('name_en')
                                ->get(['id','code','name_en','name_ar','type']),
            'currencies' => $this->currencyService->activeForDropdown($companyId),
        ]);
    }

    public function store(ProductRequest $request)
    {
        $product = $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );
        return redirect()->route('settings.products.index')
            ->with('success', "Product [{$product->code}] created successfully.");
    }

    public function edit(Request $request, Product $product): Response
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Settings/Products/Form', [
            'product'    => $product->load(['category', 'primaryUom', 'uomLines.uom',
                                            'revenueAccount', 'cogsAccount', 'inventoryAccount']),
            'categories' => $this->service->categoriesForDropdown($companyId),
            'uoms'       => \App\Models\UnitOfMeasure::withoutGlobalScopes()
                                ->forCompany($companyId)->active()->orderBy('type')->orderBy('name_en')
                                ->get(['id','code','name_en','name_ar','type']),
            'currencies' => $this->currencyService->activeForDropdown($companyId),
        ]);
    }

    public function update(ProductRequest $request, Product $product)
    {
        $this->service->update($product, $request->validated(), $request->user());
        return redirect()->route('settings.products.index')
            ->with('success', "Product [{$product->code}] updated.");
    }

    public function toggleActive(Request $request, Product $product)
    {
        $this->service->toggleActive($product, $request->user());
        return back()->with('success', 'Product status updated.');
    }

    public function addPrice(Request $request)
    {
        $validated = $request->validate([
            'product_id'     => ['required','integer','exists:products,id'],
            'price_type'     => ['required','in:selling_price,purchase_price'],
            'uom_id'         => ['required','integer','exists:units_of_measure,id'],
            'currency_id'    => ['required','integer','exists:currencies,id'],
            'price'          => ['required','numeric','min:0.0001'],
            'effective_date' => ['required','date'],
            'notes'          => ['nullable','string','max:255'],
        ]);
        try {
            $this->service->addPrice($this->companyId($request), $validated, $request->user());
            return back()->with('success', 'Price saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function prices(Request $request, Product $product)
    {
        return response()->json(
            $product->prices()->with(['uom','currency','createdBy:id,name'])->get()
        );
    }
}
