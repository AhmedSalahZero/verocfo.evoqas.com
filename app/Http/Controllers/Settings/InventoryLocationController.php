<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  InventoryLocationController
//
//  HTTP layer for Inventory Location settings.
//  Zero business logic — all delegated to InventoryLocationService.
//
//  Routes:
//    GET    /settings/locations                     → index()
//    GET    /settings/locations/create              → create()
//    POST   /settings/locations                     → store()
//    GET    /settings/locations/{location}/edit     → edit()
//    PUT    /settings/locations/{location}          → update()
//    PATCH  /settings/locations/{location}/toggle   → toggleActive()
//
//  Only shown to companies where hasInventory() = true.
//  Enforced via middleware or controller guard.
//
//  @layer    Controller
//  @depends  App\Services\InventoryLocationService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\InventoryLocationRequest;
use App\Models\InventoryLocation;
use App\Services\InventoryLocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLocationController extends Controller
{
    public function __construct(private InventoryLocationService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/InventoryLocations/Index', [
            'locations' => $this->service->tree($this->companyId($request)),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $parentId  = $request->query('parent_id') ? (int) $request->query('parent_id') : null;
        $mains     = $this->service->mainForSelector($companyId);

        return Inertia::render('Settings/InventoryLocations/Form', [
            'location'           => null,
            'main_locations'     => $mains,
            'selected_parent_id' => $parentId,
            'selected_parent'    => $parentId ? $mains->firstWhere('id', $parentId) : null,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(InventoryLocationRequest $request)
    {
        $location = $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );

        return redirect()->route('settings.locations.index')
            ->with('success', "Location [{$location->code}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, InventoryLocation $location): Response
    {
        abort_if($location->isMaster(), 403, 'The Master Warehouse cannot be edited.');

        return Inertia::render('Settings/InventoryLocations/Form', [
            'location'           => $location->load('parent'),
            'main_locations'     => $this->service->mainForSelector($this->companyId($request)),
            'selected_parent_id' => null,
            'selected_parent'    => null,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(InventoryLocationRequest $request, InventoryLocation $location)
    {
        try {
            $this->service->update($location, $request->validated(), $request->user());
            return redirect()->route('settings.locations.index')
                ->with('success', "Location [{$location->code}] updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Request $request, InventoryLocation $location)
    {
        try {
            $this->service->toggleActive($location, $request->user());
            return back()->with('success', 'Location status updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
