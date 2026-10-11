<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FullySecuredOverdraftController
//
//  HTTP layer for Fully Secured Overdraft (223006) facility
//  contracts. Zero business logic — all delegated to
//  FullySecuredOverdraftService. Same dual-mode pattern (portfolio /
//  bank-scoped) as CleanOverdraftController, plus the CD/TD account
//  picker pattern already proven in LetterOfCreditController.
//
//  @layer    Controller
//  @depends  FullySecuredOverdraftService, CreditFacility, Bank, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\FullySecuredOverdraftRequest;
use App\Http\Requests\FinancialInstitutions\RenewFullySecuredOverdraftRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\FullySecuredOverdraftService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FullySecuredOverdraftController extends Controller
{
    public function __construct(
        private FullySecuredOverdraftService $service,
        private CurrencyService $currencyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'    => $filters,
        ]);
    }

    // ── Bank-Scoped Index ────────────────────────────────────────────

    public function bankIndex(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        $filters = $request->only('status');
        $filters['bank_id'] = $bank->id;

        return Inertia::render('FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'bank'       => $bank->only(['id', 'name_en', 'name_ar']),
            'filters'    => $filters,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223006Accounts($companyId),
            'timeDepositAccounts'   => $this->tdAccounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(FullySecuredOverdraftRequest $request)
    {
        $data = $request->validated();

        try {
            $this->service->create($this->companyId($request), $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withInput()->withErrors(['outstanding_balance' => $e->getMessage()]);
        }

        return redirect()
            ->route('fi.facilities.fully-secured-od.index')
            ->with('success', 'Fully Secured Overdraft facility created successfully.');
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'fully_secured', 404);

        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftForm', [
            'facility' => $facility->load([
                'bank:id,name_en,name_ar',
                'currency:id,code',
                'fsodTdAccount:id,account_number,currency_id,bank_id',
                'fsodTdCurrency:id,code',
                'withdrawals' => fn ($q) => $q->where('is_opening_balance', true)
                                              ->orderBy('due_date'),
                'termsHistories',
            ]),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223006Accounts($companyId),
            'timeDepositAccounts'   => $this->tdAccounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(FullySecuredOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'fully_secured', 404);

        $data = $request->validated();

        try {
            $this->service->update($this->companyId($request), $facility, $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withInput()->withErrors(['outstanding_balance' => $e->getMessage()]);
        }

        return redirect()
            ->route('fi.facilities.fully-secured-od.index')
            ->with('success', 'Fully Secured Overdraft facility updated successfully.');
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renew(RenewFullySecuredOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'fully_secured', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'fully_secured', 404);

        try {
            $this->service->deleteRenewal($this->companyId($request), $facility, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['renewal' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Renewal deleted — facility reverted to its previous terms.');
    }

    // ── Delete (whole facility) ──────────────────────────────────

    public function destroy(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'fully_secured', 404);

        try {
            $this->service->delete($this->companyId($request), $facility, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility deleted successfully.');
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * COA accounts: 223006 and all sub-accounts. Same pattern as
     * CleanOverdraftController::od223001Accounts() — withoutGlobalScopes()
     * first, since the standard COA template ships with company_id = NULL.
     */
    private function od223006Accounts(int $companyId): \Illuminate\Support\Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '223006%')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

    /**
     * Every active Time Deposit account this company holds — the
     * pool the "CD/TD Information" section picks from. Same query as
     * LetterOfCreditController::tdAccounts().
     */
    private function tdAccounts(int $companyId)
    {
        return BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('account_type', 'time_deposit')
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'account_number', 'currency_id']);
    }
}
