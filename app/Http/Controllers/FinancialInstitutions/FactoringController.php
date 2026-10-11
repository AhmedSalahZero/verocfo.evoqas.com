<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringController
//
//  HTTP layer for Factoring Companies + Factoring Agreements.
//  Zero business logic — all delegated to FactoringService.
//
//  Routes (prefix: financial-institutions, name: fi.):
//    Factoring Companies:
//      GET    /factoring-companies               → factoring-companies.index
//      GET    /factoring-companies/create        → factoring-companies.create
//      POST   /factoring-companies               → factoring-companies.store
//      GET    /factoring-companies/{co}/edit     → factoring-companies.edit
//      PUT    /factoring-companies/{co}          → factoring-companies.update
//      PATCH  /factoring-companies/{co}/toggle   → factoring-companies.toggle
//
//    Factoring Agreements:
//      GET    /factoring-agreements              → factoring-agreements.index
//      GET    /factoring-agreements/create       → factoring-agreements.create
//      POST   /factoring-agreements              → factoring-agreements.store
//      GET    /factoring-agreements/{agr}/edit   → factoring-agreements.edit
//      PUT    /factoring-agreements/{agr}        → factoring-agreements.update
//      PATCH  /factoring-agreements/{agr}/toggle → factoring-agreements.toggle
//      POST   /factoring-agreements/{agr}/renew         → factoring-agreements.renew
//      DELETE /factoring-agreements/{agr}/renew         → factoring-agreements.delete-renewal
//
//  v1.1 — Agreement Renewal added (renew(), deleteRenewal()).
//    agreementsIndex()/agreementsEdit() now eager-load
//    termsHistories() via the service so the Vue page can show the
//    chapter timeline and drive the Renew/Delete buttons without a
//    second request — same pattern as CleanOverdraftController.
//
//  @layer    Controller
//  @depends  FactoringService, FactoringCompany, FactoringAgreement
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\FactoringAgreementRequest;
use App\Http\Requests\FinancialInstitutions\FactoringCompanyRequest;
use App\Http\Requests\FinancialInstitutions\RenewFactoringAgreementRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\FactoringCompany;
use App\Models\Partner;
use App\Services\FinancialInstitutions\FactoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FactoringController extends Controller
{
    public function __construct(private FactoringService $service) {}

    // ── Factoring Companies ───────────────────────────────────────

    public function companiesIndex(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Factoring/CompaniesIndex', [
            'companies' => $this->service->paginateCompanies($this->companyId($request), $request->only('search')),
            'filters'   => $request->only('search'),
        ]);
    }

    public function companiesCreate()
    {
        return Inertia::render('FinancialInstitutions/Factoring/CompanyForm', ['company' => null]);
    }

    public function companiesStore(FactoringCompanyRequest $request)
    {
        $data    = $request->validated();
        $company = $this->service->createCompany($this->companyId($request), $data, $request->user());
        return redirect()->route('fi.factoring-companies.index')->with('success', "Factoring company [{$company->name_en}] created successfully.");
    }

    public function companiesEdit(FactoringCompany $factoringCompany)
    {
        return Inertia::render('FinancialInstitutions/Factoring/CompanyForm', ['company' => $factoringCompany]);
    }

    public function companiesUpdate(FactoringCompanyRequest $request, FactoringCompany $factoringCompany)
    {
        $data = $request->validated();
        $this->service->updateCompany($factoringCompany, $data, $request->user());
        return redirect()->route('fi.factoring-companies.index')->with('success', 'Factoring company updated successfully.');
    }

    public function companiesToggle(Request $request, FactoringCompany $factoringCompany)
    {
        $this->service->toggleCompany($factoringCompany, $request->user());
        return back()->with('success', $factoringCompany->is_active ? 'Factoring company deactivated.' : 'Factoring company activated.');
    }

    // ── Factoring Agreements ──────────────────────────────────────

    public function agreementsIndex(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Factoring/AgreementsIndex', [
            'agreements'        => $this->service->paginateAgreements($this->companyId($request), $request->only('factoring_company_id', 'agreement_type', 'status')),
            'factoringCompanies'=> FactoringCompany::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'           => $request->only('factoring_company_id', 'agreement_type', 'status'),
        ]);
    }

    public function agreementsCreate(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Factoring/AgreementForm', [
            'agreement'          => null,
            'factoringCompanies' => FactoringCompany::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'coaAccounts'        => ChartOfAccount::whereIn('code', ['224001', '224002'])->get(['id', 'code', 'name_en']),
            'partners'           => Partner::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'types']),
        ]);
    }

    public function agreementsStore(FactoringAgreementRequest $request)
    {
        $data      = $request->validated();
        $agreement = $this->service->createAgreement($this->companyId($request), $data, $request->user());
        return redirect()->route('fi.factoring-agreements.index')->with('success', "Factoring agreement created successfully.");
    }

    public function agreementsEdit(Request $request, FactoringAgreement $factoringAgreement)
    {
        return Inertia::render('FinancialInstitutions/Factoring/AgreementForm', [
            'agreement'          => $factoringAgreement->load(['factoringCompany:id,name_en', 'coaAccount:id,code,name_en', 'termsHistories']),
            'factoringCompanies' => FactoringCompany::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'coaAccounts'        => ChartOfAccount::whereIn('code', ['224001', '224002'])->get(['id', 'code', 'name_en']),
            'partners'           => Partner::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'types']),
        ]);
    }

    public function agreementsUpdate(FactoringAgreementRequest $request, FactoringAgreement $factoringAgreement)
    {
        $data = $request->validated();
        $this->service->updateAgreement($factoringAgreement, $data, $request->user());
        return redirect()->route('fi.factoring-agreements.index')->with('success', 'Factoring agreement updated successfully.');
    }

    public function agreementsToggle(Request $request, FactoringAgreement $factoringAgreement)
    {
        $this->service->toggleAgreement($factoringAgreement, $request->user());
        return back()->with('success', 'Agreement status updated.');
    }

    // ── Agreement Renewal ──────────────────────────────────────────

    public function renew(RenewFactoringAgreementRequest $request, FactoringAgreement $factoringAgreement)
    {
        try {
            $this->service->renewAgreement($this->companyId($request), $factoringAgreement, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Agreement renewed successfully.');
    }

    public function deleteRenewal(Request $request, FactoringAgreement $factoringAgreement)
    {
        try {
            $this->service->deleteRenewal($this->companyId($request), $factoringAgreement, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['renewal' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Renewal deleted — agreement reverted to its previous terms.');
    }

}
