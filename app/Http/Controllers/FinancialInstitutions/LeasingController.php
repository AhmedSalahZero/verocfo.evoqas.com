<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingController
//
//  HTTP layer for Leasing Companies + Leasing Contracts.
//  Zero business logic — all delegated to LeasingService.
//
//  Routes (prefix: financial-institutions, name: fi.):
//    Leasing Companies:
//      GET    /leasing-companies               → leasing-companies.index
//      GET    /leasing-companies/create        → leasing-companies.create
//      POST   /leasing-companies               → leasing-companies.store
//      GET    /leasing-companies/{co}/edit     → leasing-companies.edit
//      PUT    /leasing-companies/{co}          → leasing-companies.update
//      PATCH  /leasing-companies/{co}/toggle   → leasing-companies.toggle
//
//    Leasing Contracts:
//      GET    /leasing-contracts                       → leasing-contracts.index
//      GET    /leasing-contracts/create                → leasing-contracts.create
//      POST   /leasing-contracts                        → leasing-contracts.store
//      GET    /leasing-contracts/{contract}/edit        → leasing-contracts.edit
//      PUT    /leasing-contracts/{contract}             → leasing-contracts.update
//      POST   /leasing-contracts/import-cheques          → leasing-contracts.import-cheques
//
//  Fix log:
//    - currencies: now built via LeasingService::activeCurrencies()
//      (company_active_currencies pivot). Currency::forCompany() alone
//      never worked — silently dropped every system currency.
//    - bankAccounts: now includes currency_id so the Vue form can
//      filter the dropdown to only accounts matching the contract's
//      selected currency.
//    - contractRules(): added currency_id, exchange_rate, cheques.*
//    - AUDIT M5 (Sept 2026): the lease actions (record payment,
//      post wear-down, final purchase) now go through Form Requests
//      (RecordLeasePaymentRequest, PostLeaseWearDownRequest,
//      LeaseFinalPurchaseRequest) instead of reading raw request
//      input. Business-rule refusals from LeaseAmortizationService
//      (\DomainException) now come back as a red message on the
//      screen instead of a server error page — see attempt().
//
//  @layer    Controller
//  @depends  LeasingService, LeasingCompany, LeasingContract
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\ImportLeaseChequesRequest;
use App\Http\Requests\FinancialInstitutions\LeasingCompanyRequest;
use App\Http\Requests\FinancialInstitutions\LeasingContractRequest;
use App\Http\Requests\FinancialInstitutions\LeaseFinalPurchaseRequest;
use App\Http\Requests\FinancialInstitutions\PostLeaseWearDownRequest;
use App\Http\Requests\FinancialInstitutions\RecordLeasePaymentRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\LeasingCompany;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Services\FinancialInstitutions\LeasingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeasingController extends Controller
{
    public function __construct(private LeasingService $service) {}

    // ── Leasing Companies ─────────────────────────────────────────

    public function companiesIndex(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Leasing/CompaniesIndex', [
            'companies' => $this->service->paginateCompanies($this->companyId($request), $request->only('search')),
            'filters'   => $request->only('search'),
        ]);
    }

    public function companiesCreate()
    {
        return Inertia::render('FinancialInstitutions/Leasing/CompanyForm', ['company' => null]);
    }

    public function companiesStore(LeasingCompanyRequest $request)
    {
        $data    = $request->validated();
        $company = $this->service->createCompany($this->companyId($request), $data, $request->user());
        return redirect()->route('fi.leasing-companies.index')->with('success', "Leasing company [{$company->name_en}] created successfully.");
    }

    public function companiesEdit(LeasingCompany $leasingCompany)
    {
        return Inertia::render('FinancialInstitutions/Leasing/CompanyForm', ['company' => $leasingCompany]);
    }

    public function companiesUpdate(LeasingCompanyRequest $request, LeasingCompany $leasingCompany)
    {
        $data = $request->validated();
        $this->service->updateCompany($leasingCompany, $data, $request->user());
        return redirect()->route('fi.leasing-companies.index')->with('success', 'Leasing company updated successfully.');
    }

    public function companiesToggle(Request $request, LeasingCompany $leasingCompany)
    {
        $this->service->toggleCompany($leasingCompany, $request->user());
        return back()->with('success', $leasingCompany->is_active ? 'Leasing company deactivated.' : 'Leasing company activated.');
    }

    // ── Leasing Contracts ─────────────────────────────────────────

    public function contractsIndex(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Leasing/ContractsIndex', [
            'contracts'        => $this->service->paginateContracts($this->companyId($request), $request->only('leasing_company_id', 'contract_type', 'status')),
            'leasingCompanies' => LeasingCompany::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'          => $request->only('leasing_company_id', 'contract_type', 'status'),
        ]);
    }

    public function contractsCreate(Request $request)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('FinancialInstitutions/Leasing/ContractForm', [
            'contract'             => null,
            'leasingCompanies'     => LeasingCompany::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'bankAccounts'         => BankAccount::active()->where('account_type', 'current')->with('bank:id,name_en')->get(['id', 'bank_id', 'account_name', 'currency_id']),
            'expenseAccounts'      => ChartOfAccount::where('code', 'like', '5%')->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name_en']),
            'liabilityAccounts'    => $this->liabilityAccounts(),
            'currencies'           => $this->service->activeCurrencies($companyId),
            'companyBaseCurrencyId'=> $this->service->functionalCurrencyId($companyId),
        ]);
    }

    public function contractsStore(LeasingContractRequest $request)
    {
        $data     = $request->validated();
        $contract = $this->service->createContract($this->companyId($request), $data, $request->user());
        return redirect()->route('fi.leasing-contracts.index')->with('success', "Leasing contract [{$contract->asset_description}] created successfully.");
    }

    public function contractsEdit(Request $request, LeasingContract $leasingContract)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('FinancialInstitutions/Leasing/ContractForm', [
            'contract'             => $leasingContract->load(['leasingCompany:id,name_en', 'bankAccount:id,account_name', 'bankLiabilityAccount:id,code,name_en', 'currency:id,code', 'cheques']),
            'leasingCompanies'     => LeasingCompany::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'bankAccounts'         => BankAccount::active()->where('account_type', 'current')->with('bank:id,name_en')->get(['id', 'bank_id', 'account_name', 'currency_id']),
            'expenseAccounts'      => ChartOfAccount::where('code', 'like', '5%')->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name_en']),
            'liabilityAccounts'    => $this->liabilityAccounts(),
            'currencies'           => $this->service->activeCurrencies($companyId),
            'companyBaseCurrencyId'=> $this->service->functionalCurrencyId($companyId),
        ]);
    }

    public function contractsUpdate(LeasingContractRequest $request, LeasingContract $leasingContract)
    {
        $data = $request->validated();
        $this->service->updateContract($leasingContract, $data, $request->user());
        return redirect()->route('fi.leasing-contracts.index')->with('success', 'Leasing contract updated successfully.');
    }

    /**
     * Parses an uploaded Excel cheque schedule and returns the rows
     * as JSON for the user to review in the repeater before saving.
     * Does not write anything to the database.
     */
    public function importCheques(ImportLeaseChequesRequest $request)
    {
        $rows = $this->service->parseChequesExcel($request->file('file'));

        return response()->json(['rows' => $rows]);
    }

    /**
     * NEW — mirrors expenseAccounts' exact pattern one line above it.
     * Filtered to '2%' as a starting guess for the liability account
     * family — CONFIRM this prefix matches your real COA structure;
     * adjust the one line below if liability accounts live under a
     * different code range.
     */
    private function liabilityAccounts()
    {
        return ChartOfAccount::where('code', 'like', '2%')->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name_en']);
    }

    // ── Lease Amortization Engine (Piece 2-5, July 2026) ───────────

    public function activate(Request $request, LeasingContract $leasingContract, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        return $this->attempt(
            fn () => $amortization->activate($leasingContract, $request->user()),
            fn () => redirect()->route('fi.leasing-contracts.schedule', $leasingContract)
                ->with('success', 'Lease activated — schedule generated and opening entry posted.')
        );
    }

    public function schedule(LeasingContract $leasingContract, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        return Inertia::render('FinancialInstitutions/Leasing/AmortizationSchedule', [
            'contract'      => $leasingContract->load(['leasingCompany', 'amortizationLines', 'wearDownEntries']),
            'liabilitySplit' => $leasingContract->isActive() ? $amortization->liabilitySplitAsOf($leasingContract) : null,
        ]);
    }

    public function recordPayment(RecordLeasePaymentRequest $request, \App\Models\FinancialInstitutions\LeaseAmortizationLine $amortizationLine, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        return $this->attempt(
            fn () => $amortization->recordPayment($amortizationLine, $request->user(), $request->validated('paid_on_date')),
            fn () => back()->with('success', 'Payment recorded.')
        );
    }

    public function postWearDown(PostLeaseWearDownRequest $request, LeasingContract $leasingContract, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        $forDate = $request->validated('for_date') ? Carbon::createFromFormat('!Y-m-d', $request->validated('for_date')) : null;

        return $this->attempt(
            fn () => $amortization->postMonthlyWearDown($leasingContract, $request->user(), $forDate),
            fn () => back()->with('success', 'Asset wear-down posted for this period.')
        );
    }

    public function reverseWearDown(Request $request, \App\Models\FinancialInstitutions\LeaseWearDownEntry $wearDownEntry, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        return $this->attempt(
            fn () => $amortization->reverseWearDown($wearDownEntry, $request->user()),
            fn () => back()->with('success', 'Wear-down entry reversed — post a corrected version if needed.')
        );
    }

    public function finalPurchase(LeaseFinalPurchaseRequest $request, LeasingContract $leasingContract, \App\Services\FinancialInstitutions\LeaseAmortizationService $amortization)
    {
        $data = $request->validated();

        return $this->attempt(
            fn () => $amortization->triggerFinalPurchase($leasingContract, $request->user(), (string) $data['purchase_value'], $data['purchase_date'] ?? null),
            fn () => redirect()->route('fi.leasing-contracts.index')
                ->with('success', 'Final purchase recorded — asset now owned outright.')
        );
    }

    /**
     * Runs a lease action; a business-rule refusal (\DomainException
     * from the service) returns to the page with a red message
     * instead of a server error. (Audit M5)
     */
    private function attempt(callable $action, callable $onSuccess)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $onSuccess();
    }
}