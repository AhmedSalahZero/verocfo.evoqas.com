<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  CompanyController
//
//  Handles HTTP layer for Company CRUD — Super Admin only.
//  Zero business logic — all delegated to CompanyService.
//
//  Routes (all under auth middleware):
//    GET    /companies                  → index()
//    GET    /companies/create           → create()
//    POST   /companies                  → store()
//    GET    /companies/{company}/edit   → edit()
//    PUT    /companies/{company}        → update()
//    PATCH  /companies/{company}/toggle → toggleActive()
//    DELETE /companies/{company}        → destroy()  (hard delete)
//
//  The create() and edit() methods both pass business_sectors
//  to Companies/Form.vue — sectors are platform-level and loaded
//  once per form render (not via API).
//
//  All methods under 15 lines per Building Structure Bible Rule 8.
//
//  @layer    Controller
//  @depends  App\Services\CompanyService
//            App\Http\Requests\CompanyRequest
//            App\Models\Company
//            App\Models\BusinessSector
// ══════════════════════════════════════════════════════════════════

use App\Http\Requests\CompanyRequest;
use App\Models\BusinessSector;
use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(private CompanyService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $companies = $this->service->paginate($request->only('search', 'status', 'tier'));

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'filters'   => $request->only('search', 'status', 'tier'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(): Response
    {
        return Inertia::render('Companies/Form', [
            'company'          => null,
            'business_sectors' => BusinessSector::active()->get(['id', 'name_en', 'name_ar']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(CompanyRequest $request)
    {
        $company = $this->service->create($request->validated(), $request->user());

        return redirect()->route('companies.index')
            ->with('success', "Company [{$company->name_en}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Company $company): Response
    {
        return Inertia::render('Companies/Form', [
            'company'          => $company,
            'business_sectors' => BusinessSector::active()->get(['id', 'name_en', 'name_ar']),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(CompanyRequest $request, Company $company)
    {
        $this->service->update($company, $request->validated(), $request->user());
        return redirect()->route('companies.index')
            ->with('success', "Company [{$company->name_en}] updated successfully.");
    }

    // ── Toggle active ─────────────────────────────────────────────

    public function toggleActive(Request $request, Company $company)
    {
        $this->service->toggleActive($company, $request->user());
        $status = $company->fresh()->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Company [{$company->name_en}] {$status}.");
    }

    // ── Destroy (hard delete) ────────────────────────────────────
    //
    // Deliberately not a FormRequest — the only real "validation"
    // here is the type-the-company-name confirmation, checked
    // directly against the company being deleted (a FormRequest
    // can't easily reach the route-bound model to compare against).
    // Super Admin check is explicit here (not just left to the
    // route group) given how irreversible this action is.

    public function destroy(Request $request, Company $company)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if ($request->input('confirm_name') !== $company->name_en) {
            return back()->withErrors([
                'confirm_name' => 'Type the company name exactly to confirm deletion.',
            ]);
        }

        $name = $company->name_en;
        $this->service->destroy($company, $request->user());

        return redirect()->route('companies.index')
            ->with('success', "Company [{$name}] and everything under it — including its users — has been permanently deleted.");
    }
}
