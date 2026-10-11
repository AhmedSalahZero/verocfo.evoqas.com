<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  CostCenterController
//
//  HTTP layer for Cost Centers settings.
//  Zero business logic — all delegated to CostCenterService.
//
//  Routes (under auth + active.company middleware):
//    GET    /settings/cost-centers                     → index()
//    GET    /settings/cost-centers/create              → create()
//    POST   /settings/cost-centers                     → store()
//    GET    /settings/cost-centers/{costCenter}/edit   → edit()
//    PUT    /settings/cost-centers/{costCenter}        → update()
//    PATCH  /settings/cost-centers/{costCenter}/toggle → toggleActive()
//
//  The create form receives:
//    - main_cost_centers: list of Main CCs for parent selector
//    - pre-selected parent_id from URL query when clicking
//      "Add Sub" from the Index tree
//
//  @layer    Controller
//  @depends  App\Services\CostCenterService
//            App\Models\CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CostCenterRequest;
use App\Models\CostCenter;
use App\Services\CostCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostCenterController extends Controller
{
    public function __construct(private CostCenterService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/CostCenters/Index', [
            'costCenters' => $this->service->tree($this->companyId($request)),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $parentId = $request->query('parent_id') ? (int) $request->query('parent_id') : null;
        $mains    = $this->service->mainCostCenters($this->companyId($request));

        return Inertia::render('Settings/CostCenters/Form', [
            'costCenter'         => null,
            'main_cost_centers'  => $mains,
            'selected_parent_id' => $parentId,
            'selected_parent'    => $parentId ? $mains->firstWhere('id', $parentId) : null,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(CostCenterRequest $request)
    {
        $cc = $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );

        return redirect()->route('settings.cost-centers.index')
            ->with('success', "Cost Center [{$cc->code}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CostCenter $costCenter): Response
    {
        return Inertia::render('Settings/CostCenters/Form', [
            'costCenter'         => $costCenter->load('parent'),
            'main_cost_centers'  => $this->service->mainCostCenters($this->companyId($request)),
            'selected_parent_id' => null,
            'selected_parent'    => null,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(CostCenterRequest $request, CostCenter $costCenter)
    {
        $this->service->update($costCenter, $request->validated(), $request->user());

        return redirect()->route('settings.cost-centers.index')
            ->with('success', "Cost Center [{$costCenter->code}] updated successfully.");
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Request $request, CostCenter $costCenter)
    {
        try {
            $this->service->toggleActive($costCenter, $request->user());
            return back()->with('success', 'Cost center status updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
