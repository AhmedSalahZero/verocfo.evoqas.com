<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StandardRateController
//
//  HTTP layer for the shared labor/overhead annual-rate mechanism.
//  Gated by Company::isManufacturing(). Draft/Active/Superseded
//  lifecycle (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md,
//  Section 2) — mirrors BillOfMaterialController: create + edit share
//  one Vue page (Rule 2), activate() is the one extra action beyond
//  plain CRUD.
//
//  @layer    Controller
//  @depends  StandardRateService, StandardRateRequest, StandardRateMonthlyOverrideRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StandardRateMonthlyOverrideRequest;
use App\Http\Requests\Accounting\StandardRateRequest;
use App\Models\FiscalYear;
use App\Models\LaborCategory;
use App\Models\OverheadPool;
use App\Models\StandardRate;
use App\Services\Accounting\StandardRateService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StandardRateController extends Controller
{
    public function __construct(private StandardRateService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/StandardRateIndex', [
            'rates'   => $this->service->paginate($this->companyId($request), $request->only('fiscal_year_id', 'rate_type')),
            'filters' => $request->only('fiscal_year_id', 'rate_type'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/StandardRateForm', [
            'fiscalYears'    => FiscalYear::where('company_id', $companyId)->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']),
            'overheadPools'  => OverheadPool::where('company_id', $companyId)->active()->get(['id', 'name', 'allocation_base']),
            'laborCategories' => LaborCategory::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Request $request, StandardRate $rate)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/StandardRateForm', [
            'rate'           => $rate->load('fiscalYear', 'overheadPool', 'laborCategory'),
            'fiscalYears'    => FiscalYear::where('company_id', $companyId)->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']),
            'overheadPools'  => OverheadPool::where('company_id', $companyId)->active()->get(['id', 'name', 'allocation_base']),
            'laborCategories' => LaborCategory::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StandardRateRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.standard-rates.index')->with('success', 'Standard rate created.');
    }

    public function update(StandardRateRequest $request, StandardRate $rate)
    {
        $this->service->update($this->companyId($request), $rate, $request->validated(), $request->user());

        return redirect()->route('accounting.standard-rates.index')->with('success', 'Standard rate updated.');
    }

    public function activate(Request $request, StandardRate $rate)
    {
        $this->service->activate($this->companyId($request), $rate, $request->user());

        return back()->with('success', 'This version is now active.');
    }

    public function storeMonthlyOverride(StandardRateMonthlyOverrideRequest $request, StandardRate $rate)
    {
        $data = $request->validated();
        $this->service->setMonthlyOverride($rate, $data['year'], $data['month'], $data['rate'], $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'Monthly override saved.');
    }
}
