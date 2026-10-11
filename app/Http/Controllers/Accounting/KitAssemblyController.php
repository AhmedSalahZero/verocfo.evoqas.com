<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitAssemblyController
//
//  HTTP layer for the "assemble N units of this kit" transaction and
//  its reversal. Zero business logic — delegated to
//  KitAssemblyService. Powers the "Assembly / Kits" sidebar group's
//  "Assemblies" item, gated by Company::hasInventory().
//
//  Create-only, like every other financial write in this app (Rule:
//  can_reverse replaces can_delete) — no edit/update. To correct a
//  mistaken assembly, reverse it (see KitAssemblyService::reverse()).
//
//  @layer    Controller
//  @depends  KitAssemblyService, KitAssemblyRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\KitAssemblyRequest;
use App\Models\InventoryLocation;
use App\Models\KitAssembly;
use App\Models\KitRecipe;
use App\Services\Accounting\KitAssemblyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KitAssemblyController extends Controller
{
    public function __construct(private KitAssemblyService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/KitAssemblyIndex', [
            'assemblies' => $this->service->paginate($this->companyId($request), $request->only('status', 'product_id')),
            'filters'    => $request->only('status', 'product_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/KitAssemblyForm', [
            'recipes'   => KitRecipe::with('product:id,code,name_en', 'lines.componentProduct:id,code,name_en')
                ->where('company_id', $companyId)
                ->active()
                ->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function store(KitAssemblyRequest $request)
    {
        $companyId = $this->companyId($request);
        $data      = $request->validated();
        $recipe    = KitRecipe::where('company_id', $companyId)->findOrFail($data['kit_recipe_id']);

        $this->service->assemble(
            $companyId,
            $recipe,
            (string) $data['quantity'],
            $data['inventory_location_id'],
            $request->user(),
            $data['assembly_date'] ?? null,
            $data['notes'] ?? null,
        );

        return redirect()->route('accounting.kit-assemblies.index')->with('success', 'Kit assembled.');
    }

    public function reverse(Request $request, KitAssembly $assembly)
    {
        $this->service->reverse($assembly, $request->user());

        return back()->with('success', 'Kit assembly reversed.');
    }
}
