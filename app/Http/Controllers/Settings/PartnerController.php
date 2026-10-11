<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  PartnerController
//
//  HTTP layer for Partner management — Settings module.
//  Zero business logic — all delegated to PartnerService.
//
//  Routes (under auth + active.company middleware):
//    GET    /settings/partners                   → index()
//    GET    /settings/partners/create            → create()
//    POST   /settings/partners                   → store()
//    GET    /settings/partners/{partner}/edit    → edit()
//    PUT    /settings/partners/{partner}         → update()
//    PATCH  /settings/partners/{partner}/toggle  → toggleActive()
//
//  The form (create + edit) receives:
//    - business_sectors  : all active sectors for the selector
//    - payment_terms     : system + company terms for dropdowns
//    - cost_centers      : active cost centers for employee type
//
//  company_id always resolved via $this->companyId($request).
//  All methods under 15 lines per Structure Bible Rule 3.
//
//  @layer    Controller
//  @depends  App\Services\PartnerService
//            App\Http\Requests\Settings\PartnerRequest
//            App\Models\Partner, BusinessSector, PaymentTerm, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PartnerRequest;
use App\Models\BusinessSector;
use App\Models\CostCenter;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\SalesChannel;
use App\Services\PartnerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerController extends Controller
{
    public function __construct(private PartnerService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $partners = $this->service->paginate(
            $this->companyId($request),
            $request->only('search', 'type', 'is_active')
        );

        return Inertia::render('Settings/Partners/Index', [
            'partners' => $partners,
            'filters'  => $request->only('search', 'type', 'is_active'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        return Inertia::render('Settings/Partners/Form', [
            'partner'          => null,
            'business_sectors' => BusinessSector::active()->get(['id', 'name_en', 'name_ar']),
            'payment_terms'    => PaymentTerm::forCompany($this->companyId($request))->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'cost_centers'     => CostCenter::where('is_active', true)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'sales_channels'   => SalesChannel::forCompany($this->companyId($request))->active()->orderBy('sort_order')->get(['id', 'name_en', 'name_ar', 'is_system']),
            'landing_cost_accounts' => $this->landingCostAccounts($this->companyId($request)),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(PartnerRequest $request)
    {
        $partner = $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );

        return redirect()->route('settings.partners.index')
            ->with('success', "Partner [{$partner->name_en}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, Partner $partner): Response
    {
        return Inertia::render('Settings/Partners/Form', [
            'partner'          => $partner->load(['contacts', 'businessSector', 'collectionTerm', 'paymentTerm', 'costCenter', 'salesChannel']),
            'business_sectors' => BusinessSector::active()->get(['id', 'name_en', 'name_ar']),
            'payment_terms'    => PaymentTerm::forCompany($this->companyId($request))->active()->get(['id', 'code', 'name_en', 'name_ar']),
            'cost_centers'     => CostCenter::where('is_active', true)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'sales_channels'   => SalesChannel::forCompany($this->companyId($request))->active()->orderBy('sort_order')->get(['id', 'name_en', 'name_ar', 'is_system']),
            'landing_cost_accounts' => $this->landingCostAccounts($this->companyId($request)),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(PartnerRequest $request, Partner $partner)
    {
        $this->service->update($partner, $request->validated(), $request->user());

        return redirect()->route('settings.partners.index')
            ->with('success', "Partner [{$partner->name_en}] updated successfully.");
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Request $request, Partner $partner)
    {
        $this->service->toggleActive($partner, $request->user());

        return back()->with('success', __('Partner status updated.'));
    }

    /** Every child of 146 (Landing Cost Clearing) — see LandingCostController::clearingAccounts() for the identical query. */
    private function landingCostAccounts(int $companyId)
    {
        $parent = \App\Models\ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', '146')->first();

        if (! $parent) {
            return collect();
        }

        return \App\Models\ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('parent_id', $parent->id)->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']);
    }
}
