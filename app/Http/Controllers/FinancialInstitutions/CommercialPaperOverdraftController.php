<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CommercialPaperOverdraftController
//
//  HTTP layer for OD Against Commercial Paper (223002) facility
//  contracts. Zero business logic — all delegated to
//  CommercialPaperOverdraftService.
//
//  Built directly against the already-fixed patterns from
//  CleanOverdraftController (no need to rediscover those bugs):
//    - COA lookup uses withoutGlobalScopes() + forCompany() so
//      system-shared accounts (company_id = NULL) aren't filtered
//      out by the global CompanyScope.
//    - Currency lookup uses withoutGlobalScopes() against the
//      company_active_currencies pivot, same reasoning.
//    - One Vue page (CommercialPaperOverdraftIndex) serves both
//      the portfolio view (index()) and a single bank's view
//      (bankIndex()) — see CleanOverdraftController v1.6 for why.
//
//  This form has no "Outstanding Breakdown" section (unlike Clean
//  Overdraft) — by design, confirmed with the business owner.
//  Cheque-level detail isn't tracked yet; that's a later build.
//  In its place, this facility has a lending-tier rate card
//  (lending_tiers) and a single max_lending_limit_per_customer
//  cap, both specific to this facility type.
//
//  @layer    Controller
//  @depends  CommercialPaperOverdraftService, CreditFacility, Bank
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\CommercialPaperOverdraftRequest;
use App\Http\Requests\FinancialInstitutions\RenewCommercialPaperOverdraftRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\CommercialPaperOverdraftService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CommercialPaperOverdraftController extends Controller
{
    public function __construct(
        private CommercialPaperOverdraftService $service,
        private CurrencyService $currencyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftIndex', [
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

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftIndex', [
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

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223002Accounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(CommercialPaperOverdraftRequest $request)
    {
        $data = $request->validated();
        $this->service->create($this->companyId($request), $data, $request->user());

        return redirect()
            ->route('fi.facilities.commercial-paper-od.index')
            ->with('success', 'OD Against Commercial Paper facility created successfully.');
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'commercial_paper', 404);

        $companyId = $this->companyId($request);

        // Facility Renewal — Edit only ever shows/replaces the
        // CURRENTLY LIVE chapter's tiers, never an earlier frozen
        // chapter's rate card. setRelation() (not load()) so this
        // still serializes under the 'lending_tiers' key the Vue
        // form already reads, rather than introducing a second key.
        $facility->load(['bank:id,name_en,name_ar', 'currency:id,code']);
        $facility->setRelation('lendingTiers', $facility->currentLendingTiers()->get());

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftForm', [
            'facility'              => $facility,
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223002Accounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(CommercialPaperOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'commercial_paper', 404);

        $data = $request->validated();
        $this->service->update($this->companyId($request), $facility, $data, $request->user());

        return redirect()
            ->route('fi.facilities.commercial-paper-od.index')
            ->with('success', 'OD Against Commercial Paper facility updated successfully.');
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renew(RenewCommercialPaperOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'commercial_paper', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'commercial_paper', 404);

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
        abort_if($facility->facility_type !== 'commercial_paper', 404);

        try {
            $this->service->delete($this->companyId($request), $facility, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility deleted successfully.');
    }

    // ── Private helpers ───────────────────────────────────────────

    // activeCurrencies() / baseCurrencyId() removed (July 2026 audit) —
    // consolidated into CurrencyService::activeCurrenciesOrdered() /
    // functionalCurrencyId().

    /**
     * COA accounts: 223002 and all sub-accounts. Same pattern as
     * CleanOverdraftController::od223001Accounts() — withoutGlobalScopes()
     * + forCompany() so system-shared accounts (company_id = NULL)
     * are included, not silently filtered out.
     */
    private function od223002Accounts(int $companyId): \Illuminate\Support\Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '223002%')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

}
