<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockAdjustmentController
//
//  Manual stock correction (found/lost stock, damage, count
//  discrepancy). Had no sidebar entry before this build — a new nav
//  item ("Stock Adjustments") was added to HandleInertiaRequests
//  alongside Inventory/Transfers, gated by the same
//  Company::hasInventory() check.
//
//  Create-only, like every other financial write in this app (Rule:
//  can_reverse replaces can_delete) — no edit/update. To correct a
//  wrong adjustment, record an offsetting one in the other direction
//  with a note explaining why, same as any other posted transaction.
//
//  MFG-002 write-off fix (confirmed with Mahmoud, 2026-09) — a Job
//  Order's own Finished Goods segment can now be targeted directly by
//  an 'out' adjustment (damaged/lost before ever being delivered),
//  via StockAdjustmentService rather than StockLedgerService
//  directly. jobOrderStockBalances passed to the create form lists
//  every Job Order that currently holds its own stock, so the form
//  can offer a picker without a live per-keystroke lookup.
//
//  @layer    Controller
//  @depends  StockAdjustmentService, StockAdjustmentRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StockAdjustmentRequest;
use App\Models\InventoryLocation;
use App\Models\JobOrderStockBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Accounting\StockAdjustmentService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private StockLedgerService $service,
        private StockAdjustmentService $adjustmentService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockAdjustmentIndex', [
            'movements' => $this->service->paginateMovements(
                $companyId,
                [StockMovement::TYPE_ADJUSTMENT_IN, StockMovement::TYPE_ADJUSTMENT_OUT],
                $request->only('product_id', 'inventory_location_id')
            ),
            'filters'   => $request->only('product_id', 'inventory_location_id'),
            'pending'   => $this->adjustmentService->pending($companyId),
            'canApprove' => $request->user()->canOnFeature('approve', 'accounting', 'inventory_adjustment'),
            'products'  => Product::where('company_id', $companyId)->withStock()->forDropdown()->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockAdjustmentForm', [
            'reasons'   => array_keys(StockAdjustmentService::REASONS),
            'products'  => Product::where('company_id', $companyId)->withStock()->forDropdown()->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'jobOrderStockBalances' => JobOrderStockBalance::where('company_id', $companyId)
                ->where('quantity_on_hand', '>', 0)
                ->with('jobOrder:id,job_code,product_id')
                ->get()
                ->map(fn ($balance) => [
                    'job_order_id'   => $balance->job_order_id,
                    'job_code'       => $balance->jobOrder->job_code,
                    'product_id'     => $balance->product_id,
                    'quantity_on_hand' => (string) $balance->quantity_on_hand,
                ])
                ->values(),
        ]);
    }

    /** Audit M-18 — saves a PENDING request; a second person approves it. */
    public function store(StockAdjustmentRequest $request)
    {
        try {
            $this->adjustmentService->submit($this->companyId($request), $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('accounting.stock-adjustments.index')
            ->with('success', 'Stock adjustment submitted — it posts once someone else approves it.');
    }

    public function approve(Request $request, int $adjustment)
    {
        return $this->decide($request, $adjustment, fn ($r) => $this->adjustmentService->approve($r, $request->user()), 'Stock adjustment approved and posted.');
    }

    public function reject(Request $request, int $adjustment)
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->decide($request, $adjustment, fn ($r) => $this->adjustmentService->reject($r, $request->user(), $reason), 'Stock adjustment rejected.');
    }

    private function decide(Request $request, int $id, \Closure $action, string $success)
    {
        $record = \App\Models\StockAdjustmentRequest::where('company_id', $this->companyId($request))->findOrFail($id);

        try {
            $action($record);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
