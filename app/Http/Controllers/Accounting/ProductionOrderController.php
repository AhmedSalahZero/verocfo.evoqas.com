<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProductionOrderController
//
//  HTTP layer for the Mass Production job itself. Powers the
//  "Production Orders" sidebar item (accounting.production.index,
//  replaces the old stub), gated by Company::isManufacturing().
//
//  No update() — a Production Order's core fields (BOM, quantity,
//  locations) are never edited after creation; correcting a mistake
//  means cancelling and opening a new one. show() is the working
//  page — it's where material/labor/overhead get posted while the
//  order is in_progress, and where WIP + variance are visible.
//
//  @layer    Controller
//  @depends  ProductionOrderService, ProductionOrderRequest,
//            RecordMaterialConsumptionRequest, RecordLaborCostRequest,
//            RecordOverheadCostRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ProductionOrderRequest;
use App\Http\Requests\Accounting\RecordLaborCostRequest;
use App\Http\Requests\Accounting\RecordMaterialConsumptionRequest;
use App\Http\Requests\Accounting\RecordOverheadCostRequest;
use App\Models\BillOfMaterial;
use App\Models\InventoryLocation;
use App\Models\OverheadPool;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Services\Accounting\ProductionOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionOrderService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/ProductionOrderIndex', [
            'orders'  => $this->service->paginate($this->companyId($request), $request->only('status', 'product_id')),
            'filters' => $request->only('status', 'product_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/ProductionOrderForm', [
            'products'  => Product::where('company_id', $companyId)->where('nature', Product::NATURE_MANUFACTURED)->forDropdown()->get(),
            'boms'      => BillOfMaterial::where('company_id', $companyId)->active()->with('product:id,name_en')->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en']),
        ]);
    }

    public function show(Request $request, ProductionOrder $order)
    {
        return Inertia::render('Accounting/Manufacturing/ProductionOrderShow', [
            'order'         => $order->load('product', 'billOfMaterial.lines.componentProduct', 'rawMaterialLocation', 'outputLocation', 'stockMovements.product', 'costEntries.overheadPool', 'costEntries.costCenter'),
            'wipBalance'    => $this->service->wipBalance($order),
            'variance'      => $order->status !== ProductionOrder::STATUS_DRAFT ? $this->service->variance($order) : null,
            'overheadPools' => OverheadPool::where('company_id', $this->companyId($request))->active()->get(),
            'costCenters'   => \App\Models\CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
        ]);
    }

    public function store(ProductionOrderRequest $request)
    {
        $order = $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.production.show', $order->id)->with('success', 'Production Order created.');
    }

    public function start(Request $request, ProductionOrder $order)
    {
        $this->service->start($order, $request->user());

        return back()->with('success', 'Production Order started.');
    }

    public function complete(Request $request, ProductionOrder $order)
    {
        $this->service->complete($order, $request->user());

        return back()->with('success', 'Production Order completed — Finished Goods updated.');
    }

    // 2026-09 — full reversal (Finished Goods receipt, every cost
    // entry, every material consumption), same "Set to Draft" pattern
    // as Customer/Supplier Payment, GRN, Delivery Note.
    public function setToDraft(Request $request, ProductionOrder $order)
    {
        $this->service->setToDraft($order, $request->user());

        return back()->with('success', 'Production Order reopened to draft.');
    }

    public function consumeMaterial(RecordMaterialConsumptionRequest $request, ProductionOrder $order)
    {
        $data = $request->validated();
        $this->service->consumeMaterial($order, $data['component_product_id'], (string) $data['quantity'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null);

        return back()->with('success', 'Material consumption recorded.');
    }

    public function recordLabor(RecordLaborCostRequest $request, ProductionOrder $order)
    {
        $data = $request->validated();
        $this->service->recordLaborCost($order, (string) $data['hours'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null, $data['cost_center_id'] ?? null);

        return back()->with('success', 'Labor cost recorded.');
    }

    public function recordOverhead(RecordOverheadCostRequest $request, ProductionOrder $order)
    {
        $data = $request->validated();
        $pool = OverheadPool::where('company_id', $this->companyId($request))->findOrFail($data['overhead_pool_id']);
        $this->service->recordOverheadCost($order, $pool, (string) $data['base_quantity'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null, $data['cost_center_id'] ?? null);

        return back()->with('success', 'Overhead cost recorded.');
    }
}
