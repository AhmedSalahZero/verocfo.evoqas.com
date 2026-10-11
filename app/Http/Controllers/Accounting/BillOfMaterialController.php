<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BillOfMaterialController
//
//  HTTP layer for BOM master data. Zero business logic — delegated
//  to BillOfMaterialService. Powers the "Bill of Materials" sidebar
//  item (accounting.bom.index, replaces the old stub), gated by
//  Company::isManufacturing().
//
//  Create + Edit share one Vue page (Rule 2) — activate() is the one
//  extra action beyond plain CRUD, for switching which version is
//  the active one without creating a new version.
//
//  @layer    Controller
//  @depends  BillOfMaterialService, BillOfMaterialRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BillOfMaterialRequest;
use App\Models\BillOfMaterial;
use App\Models\LaborCategory;
use App\Models\Machine;
use App\Models\Process;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Services\Accounting\BillOfMaterialService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillOfMaterialController extends Controller
{
    public function __construct(private BillOfMaterialService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/BomIndex', [
            'boms'    => $this->service->paginate($this->companyId($request), $request->only('search', 'product_id')),
            'filters' => $request->only('search', 'product_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/BomForm', $this->formProps($request));
    }

    public function edit(Request $request, BillOfMaterial $bom)
    {
        $bom->load('product', 'lines.componentProduct', 'lines.uom', 'operations.process', 'operations.defaultMachine', 'operations.laborCategory');

        return Inertia::render('Accounting/Manufacturing/BomForm', array_merge(
            $this->formProps($request, $bom),
            // Bug fix: the Product field showed blank when editing —
            // 'product' was never eager-loaded, only the line-level
            // relations were.
            ['bom' => $bom]
        ));
    }

    public function store(BillOfMaterialRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.bom.index')->with('success', 'Bill of Materials created.');
    }

    public function update(BillOfMaterialRequest $request, BillOfMaterial $bom)
    {
        $this->service->update($this->companyId($request), $bom, $request->validated(), $request->user());

        return redirect()->route('accounting.bom.index')->with('success', 'Bill of Materials updated.');
    }

    public function activate(Request $request, BillOfMaterial $bom)
    {
        $this->service->activate($this->companyId($request), $bom, $request->user());

        return back()->with('success', 'This version is now active.');
    }

    // Bug fix: create() and edit() both used to send the SAME
    // unfiltered product list for both the "Product" field (what the
    // BOM produces) and the "Component" lines (what it consumes) —
    // every product in the company showed in both pickers. Now split
    // into the two lists each picker actually needs, plus a real UOM
    // list (previously reconstructed client-side from each product's
    // primary_uom_id with no code ever supplied, which is why the UOM
    // dropdown only ever showed dashes).
    //
    // Routing (Section 5): machines offered as a NEW default are
    // Active-only (Machine::scopeActive() — same status check backing
    // Machine::scopeEligibleForNewRoutingDefault(), Section 4.3) —
    // plus, when editing, every machine already referenced by one of
    // this BOM's own operations, even if it has since gone Under
    // Maintenance/Retired, so an existing Routing never silently
    // loses a value the form can't display.
    private function formProps(Request $request, ?BillOfMaterial $bom = null): array
    {
        $companyId = $this->companyId($request);

        $alreadyUsedMachineIds = $bom
            ? $bom->operations->pluck('default_machine_id')->unique()->values()
            : collect();

        return [
            'manufacturedProducts' => Product::where('company_id', $companyId)->manufactured()->forDropdown()->get(),
            'rawMaterials'         => Product::where('company_id', $companyId)->rawMaterial()->forDropdown()->get(),
            'uoms'                 => UnitOfMeasure::forCompany($companyId)->active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'processes'            => Process::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
            'laborCategories'      => LaborCategory::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
            'machines'             => Machine::where('company_id', $companyId)
                ->where(function ($q) use ($alreadyUsedMachineIds) {
                    $q->active();
                    if ($alreadyUsedMachineIds->isNotEmpty()) {
                        $q->orWhereIn('id', $alreadyUsedMachineIds);
                    }
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'process_id']),
        ];
    }
}
