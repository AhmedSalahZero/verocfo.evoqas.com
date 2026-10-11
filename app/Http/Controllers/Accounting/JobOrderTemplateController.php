<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderTemplateController
//
//  HTTP layer for the optional Job Order material-list template.
//  Zero business logic — delegated to JobOrderTemplateService. Gated
//  by Company::isManufacturing(), same as every other Manufacturing
//  route (a Job Order production model is still a manufacturing
//  concept even though it's structurally separate from Mass
//  Production).
//
//  Create + Edit share one Vue page (Rule 2). No activate() here,
//  unlike BillOfMaterialController — templates aren't versioned, so
//  there's no "which version is active" concept to switch.
//
//  @layer    Controller
//  @depends  JobOrderTemplateService, JobOrderTemplateRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JobOrderTemplateRequest;
use App\Models\JobOrderTemplate;
use App\Models\Product;
use App\Services\Accounting\JobOrderTemplateService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JobOrderTemplateController extends Controller
{
    public function __construct(private JobOrderTemplateService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/JobOrderTemplateIndex', [
            'templates' => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters'   => $request->only('search'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/JobOrderTemplateForm', [
            'products' => Product::where('company_id', $this->companyId($request))->forDropdown()->get(),
        ]);
    }

    public function edit(Request $request, JobOrderTemplate $template)
    {
        return Inertia::render('Accounting/Manufacturing/JobOrderTemplateForm', [
            'template' => $template->load('lines.componentProduct', 'lines.uom'),
            'products' => Product::where('company_id', $this->companyId($request))->forDropdown()->get(),
        ]);
    }

    public function store(JobOrderTemplateRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.job-order-templates.index')->with('success', 'Job Order template created.');
    }

    public function update(JobOrderTemplateRequest $request, JobOrderTemplate $template)
    {
        $this->service->update($template, $request->validated(), $request->user());

        return redirect()->route('accounting.job-order-templates.index')->with('success', 'Job Order template updated.');
    }
}
