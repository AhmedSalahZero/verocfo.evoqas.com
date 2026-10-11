<?php

namespace App\Http\Controllers\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectController
//
//  HTTP layer for Project / Contract master data.
//  Zero business logic — all delegated to ProjectService.
//
//  Routes (under auth + active.company):
//    GET    /projects                      → index()
//    GET    /projects/create               → create()
//    POST   /projects                      → store()
//    GET    /projects/{project}/edit       → edit()
//    PUT    /projects/{project}            → update()
//    PATCH  /projects/{project}/status     → changeStatus()
//
//  Fix log:
//    - customers: now uses Partner::ofType('customer') — partners.types
//      is a JSON array (a partner can be Customer + Supplier at once),
//      the old `where('partner_type', ...)` column no longer exists.
//    - currencies: now built via company_active_currencies pivot using
//      the same activeCurrencies() pattern as CleanOverdraftController.
//      Currency::forCompany() alone returned nothing — the model's own
//      global CompanyScope silently dropped every system currency
//      (company_id = NULL) before scopeForCompany()'s OR clause could
//      apply. withoutGlobalScopes() here is what actually fixes it.
//
//  v1.1 — store()/update()/changeStatus() now use dedicated Form
//    Request classes (ProjectRequest, ChangeProjectStatusRequest)
//    instead of an inline private rules() method — matches the
//    house style used by PartnerRequest/ProductRequest/etc. in the
//    Settings stream. No validation rule changed.
//
//  @layer    Controller
//  @depends  ProjectService, Project, ProjectRequest, ChangeProjectStatusRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ChangeProjectStatusRequest;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Partner;
use App\Models\Project;
use App\Services\CurrencyService;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Projects/Index', [
            'projects' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'  => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Projects/Form', [
            'project'    => null,
            'customers'  => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'currencies' => $this->currencyService->activeCurrenciesOrdered($companyId),
        ]);
    }

    public function store(ProjectRequest $request)
    {
        $data    = $request->validated();
        $project = $this->service->create($this->companyId($request), $data, $request->user());
        return redirect()->route('projects.index')->with('success', "Project [{$project->contract_code}] created successfully.");
    }

    public function edit(Request $request, Project $project)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Projects/Form', [
            'project'    => $project->load(['partner:id,name_en', 'currency:id,code']),
            'customers'  => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'currencies' => $this->currencyService->activeCurrenciesOrdered($companyId),
        ]);
    }

    public function update(ProjectRequest $request, Project $project)
    {
        $data = $request->validated();
        $this->service->update($this->companyId($request), $project, $data, $request->user());
        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    public function changeStatus(ChangeProjectStatusRequest $request, Project $project)
    {
        $data = $request->validated();

        try {
            $this->service->changeStatus(
                $this->companyId($request),
                $project,
                $data['status'],
                $request->user(),
                $data['confirm_stock_warning'] ?? false,
            );
            return back()->with('success', 'Project status updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // activeCurrencies() removed (July 2026 audit) — consolidated into
    // CurrencyService::activeCurrenciesOrdered().
}