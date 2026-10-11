<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankController
//
//  HTTP layer for Bank master + Bank Accounts + Credit Facilities.
//  Zero business logic — all delegated to BankService.
//
//  Routes (prefix: financial-institutions, name: fi.):
//    Banks:
//      GET    /banks                      → banks.index
//      GET    /banks/create               → banks.create
//      POST   /banks                      → banks.store
//      GET    /banks/{bank}/edit          → banks.edit
//      PUT    /banks/{bank}               → banks.update
//      PATCH  /banks/{bank}/toggle        → banks.toggle
//
//    Bank Accounts:
//      GET    /bank-accounts              → bank-accounts.index
//      GET    /bank-accounts/create       → bank-accounts.create
//      POST   /bank-accounts              → bank-accounts.store
//      GET    /bank-accounts/{account}/edit → bank-accounts.edit
//      PUT    /bank-accounts/{account}    → bank-accounts.update
//      PATCH  /bank-accounts/{account}/toggle → bank-accounts.toggle
//
//  @layer    Controller
//  @depends  BankService, Bank, BankAccount
//
//  v1.8 — CORRECTION to v1.7 below: that cleanup accidentally deleted
//    bankRules(), accountRules(), and accountsRepeaterRules() along
//    with the intended dead code — a real bug (the controller called
//    these methods but no longer defined them). Fixed here by
//    extracting all three into proper Form Request classes
//    (BankRequest, BankAccountRequest, BankAccountsRepeaterRequest)
//    instead of just restoring them as private methods — since this
//    file needed the Rule 3.2 Form Request treatment anyway.
//
//  v1.7 — Removed the generic facilitiesIndex/Create/Store/Edit/
//    Update methods and the fi.facilities.* routes. These rendered
//    'FinancialInstitutions/Banks/FacilitiesIndex' and 'FacilityForm'
//    — two Vue pages that were never built. This was an early
//    "one generic form for every facility type" approach, abandoned
//    in favor of the per-type controller + Vue page pattern actually
//    in use today (CleanOverdraftController, LetterOfGuaranteeController,
//    LetterOfCreditController, MtlController, ContractAssignmentOdController,
//    CommercialPaperOverdraftController — each with its own dedicated
//    routes and form). The removed code was unreachable in practice
//    (routes pointed to non-existent pages) and fully superseded.
//    BankService::paginateFacilities()/createFacility()/updateFacility()
//    are now unused too — left in place since they're harmless, but
//    flagged here in case a future cleanup wants to remove them as well.
//
//  v1.1 — accountsIndex() eager-loaded currency via the shorthand
//    'currency:id,code', which silently re-applies Currency's
//    global CompanyScope and drops every system currency
//    (company_id = NULL) — i.e. almost all of them. Loads it
//    through a closure with withoutGlobalScopes() instead. Same
//    root cause and fix as BankService::paginateFacilities() and
//    CleanOverdraftService::paginate().
//
//  v1.2 — Bank Account build:
//    - accountsCreate()/accountsEdit(): coaAccounts now select
//      'code' not 'account_code' (that column never existed on
//      chart_of_accounts — same bug already fixed in
//      LeasingController and SalesOrderController).
//    - currencies: now built via BankService::activeCurrencies()
//      (company_active_currencies pivot). Currency::forCompany()
//      alone never worked — its own global CompanyScope silently
//      drops every system currency before the scope's OR clause
//      can apply.
//    - accountsStore(): now accepts a repeater 'accounts' array and
//      creates them all in one transaction via
//      BankService::createBankAccounts().
//    - accountRules(): added interest_rate, minimum_balance_for_interest.
//    - Bonus fixes spotted while in this file (different feature,
//      same known bug patterns): facilitiesCreate()/facilitiesEdit()
//      used Partner::where('partner_type', 'supplier') — that column
//      doesn't exist, partners.types is a JSON array. Fixed to
//      Partner::ofType('supplier'). Also fixed the same
//      Currency::forCompany() issue there.
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\BankAccountRequest;
use App\Http\Requests\FinancialInstitutions\BankAccountsRepeaterRequest;
use App\Http\Requests\FinancialInstitutions\BankRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Services\FinancialInstitutions\BankService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankController extends Controller
{
    public function __construct(private BankService $service) {}

    // ── Banks ─────────────────────────────────────────────────────

    public function banksIndex(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Banks/Index', [
            'banks'   => $this->service->paginateBanks($this->companyId($request), $request->only('search')),
            'filters' => $request->only('search'),
        ]);
    }

    public function banksCreate()
    {
        return Inertia::render('FinancialInstitutions/Banks/Form', ['bank' => null]);
    }

    public function banksStore(BankRequest $request)
    {
        $data = $request->validated();
        $bank = $this->service->createBank($this->companyId($request), $data, $request->user());
        return redirect()->route('fi.banks.index')->with('success', "Bank [{$bank->name_en}] created successfully.");
    }

    public function banksEdit(Bank $bank)
    {
        return Inertia::render('FinancialInstitutions/Banks/Form', ['bank' => $bank]);
    }

    public function banksUpdate(BankRequest $request, Bank $bank)
    {
        $data = $request->validated();
        $this->service->updateBank($bank, $data, $request->user());
        return redirect()->route('fi.banks.index')->with('success', 'Bank updated successfully.');
    }

    public function banksToggle(Request $request, Bank $bank)
    {
        $this->service->toggleBank($bank, $request->user());
        return back()->with('success', $bank->is_active ? 'Bank deactivated.' : 'Bank activated.');
    }

    // ── Bank Accounts ─────────────────────────────────────────────

    public function accountsIndex(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'account_type');

        return Inertia::render('FinancialInstitutions/Banks/AccountsIndex', [
            'accounts'        => $this->service->paginateAccounts($companyId, $filters),
            'banks'           => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'currentAccounts' => BankAccount::current()->active()->with('bank:id,name_en')->orderBy('account_name')->get(['id', 'account_name', 'account_number', 'bank_id']),
            'filters'         => $filters,
        ]);
    }

    public function accountsCreate(Request $request)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('FinancialInstitutions/Banks/AccountForm', [
            'account'              => null,
            'bank'                 => $request->bank_id ? Bank::find($request->bank_id, ['id', 'name_en', 'name_ar']) : null,
            'banks'                => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'           => $this->service->activeCurrencies($companyId),
            'companyBaseCurrencyId'=> $this->service->functionalCurrencyId($companyId),
            'currentAccounts'      => BankAccount::current()->active()->with('bank:id,name_en')->orderBy('account_name')->get(['id', 'account_name', 'account_number', 'bank_id', 'coa_account_id']),
            'coaAccounts'          => ChartOfAccount::where('code', 'like', '132%')
                ->where('is_postable', true)->orderBy('code')
                ->get(['id', 'code', 'name_en']),
        ]);
    }

    public function accountsStore(BankAccountsRepeaterRequest $request)
    {
        $companyId = $this->companyId($request);
        $validated = $request->validated();
        $bank      = Bank::findOrFail($validated['accounts'][0]['bank_id']);

        try {
            $accounts = $this->service->createBankAccounts($companyId, $bank, $validated['accounts'], $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $label = count($accounts) > 1
            ? count($accounts) . ' bank accounts'
            : "Bank account [{$accounts[0]->account_name}]";

        return redirect()->route('fi.bank-accounts.index')->with('success', "{$label} created successfully.");
    }

    public function accountsEdit(Request $request, BankAccount $bankAccount)
    {
        $companyId = $this->companyId($request);
        return Inertia::render('FinancialInstitutions/Banks/AccountForm', [
            'account'              => $bankAccount->load(['bank:id,name_en,name_ar', 'currency:id,code', 'termsHistory.changedBy:id,name', 'fundingAccount:id,account_name,account_number']),
            'bank'                 => $bankAccount->bank()->first(['id', 'name_en', 'name_ar']),
            'banks'                => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'           => $this->service->activeCurrencies($companyId),
            'companyBaseCurrencyId'=> $this->service->functionalCurrencyId($companyId),
            'currentAccounts'      => BankAccount::current()->active()->with('bank:id,name_en')->orderBy('account_name')->get(['id', 'account_name', 'account_number', 'bank_id', 'coa_account_id']),
            'coaAccounts'          => ChartOfAccount::where('code', 'like', '132%')
                ->where('is_postable', true)->orderBy('code')
                ->get(['id', 'code', 'name_en']),
        ]);
    }

    public function accountsUpdate(BankAccountRequest $request, BankAccount $bankAccount)
    {
        $data = $request->validated();
        $this->service->updateBankAccount($bankAccount, $data, $request->user());
        return redirect()->route('fi.bank-accounts.index')->with('success', 'Bank account updated successfully.');
    }

    public function accountsToggle(Request $request, BankAccount $bankAccount)
    {
        $this->service->toggleBankAccount($bankAccount, $request->user());
        return back()->with('success', $bankAccount->is_active ? 'Account deactivated.' : 'Account activated.');
    }

}
