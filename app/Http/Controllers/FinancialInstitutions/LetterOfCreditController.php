<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfCreditController
//
//  HTTP layer for Letter of Credit (facility_type = 'import_lc')
//  facility AGREEMENTS. Zero business logic — delegated to
//  LetterOfCreditService. Same dual-mode pattern as every other
//  facility type.
//
//  @layer    Controller
//  @depends  LetterOfCreditService, CreditFacility, Bank, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\LetterOfCreditRequest;
use App\Http\Requests\FinancialInstitutions\RenewLetterOfCreditRequest;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLcType;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\LetterOfCreditService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LetterOfCreditController extends Controller
{
    public function __construct(
        private LetterOfCreditService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfCreditIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'    => $filters,
        ]);
    }

    public function bankIndex(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);
        $filters = $request->only('status');
        $filters['bank_id'] = $bank->id;

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfCreditIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'bank'       => $bank->only(['id', 'name_en', 'name_ar']),
            'filters'    => $filters,
        ]);
    }

    public function create(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfCreditForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'timeDepositAccounts'   => $this->tdAccounts($companyId),
            'defaultLcTypes'        => FacilityLcType::DEFAULT_TYPES,
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    public function store(LetterOfCreditRequest $request)
    {
        $data = $request->validated();
        $this->service->create($this->companyId($request), $data, $request->user());

        return redirect()->route('fi.facilities.lc.index')
            ->with('success', 'Letter of Credit facility created successfully.');
    }

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'import_lc', 404);
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfCreditForm', [
            'facility' => $facility->load(['bank:id,name_en,name_ar', 'currency:id,code', 'lcTypes', 'lcTdAccount', 'lcTdCurrency:id,code']),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'timeDepositAccounts'   => $this->tdAccounts($companyId),
            'defaultLcTypes'        => FacilityLcType::DEFAULT_TYPES,
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    public function update(LetterOfCreditRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'import_lc', 404);

        $data = $request->validated();
        $this->service->update($this->companyId($request), $facility, $data, $request->user());

        return redirect()->route('fi.facilities.lc.index')
            ->with('success', 'Letter of Credit facility updated successfully.');
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renew(RenewLetterOfCreditRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'import_lc', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'import_lc', 404);

        try {
            $this->service->deleteRenewal($this->companyId($request), $facility, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['renewal' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Renewal deleted — facility reverted to its previous terms.');
    }

    // ── Private helpers ───────────────────────────────────────────

    // activeCurrencies() / baseCurrencyId() removed (July 2026 audit) —
    // consolidated into CurrencyService::activeCurrenciesOrdered() /
    // functionalCurrencyId().

    private function tdAccounts(int $companyId)
    {
        return BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('account_type', 'time_deposit')
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'account_number', 'currency_id']);
    }
}
