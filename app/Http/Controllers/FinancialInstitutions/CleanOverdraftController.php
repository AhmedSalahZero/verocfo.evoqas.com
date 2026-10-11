<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CleanOverdraftController
//
//  HTTP layer for Clean Overdraft (223001) facility contracts.
//  Zero business logic — all delegated to CleanOverdraftService.
//
//  Full audit fixes applied (v1.3):
//    1. COA column: 'account_code' → 'code' (actual column name)
//    2. COA query: uses scopeForCompany() (system + company accounts)
//       not where('company_id') alone — system accounts have NULL company_id
//    3. COA filter: '223001' code search uses correct column 'code'
//    4. COA select: returns 'code' not 'account_code'
//    5. Currency: uses CompanyActiveCurrency pivot (fixed in v1.2)
//    6. baseCurrencyId: uses CompanyActiveCurrency.is_functional
//    7. Index: removed reference to non-existent CleanOverdraftIndex page
//       redirects to generic facilities index until dedicated page is built
//    8. store(): removed unused $facility variable assignment
//
//  v1.4 — Currency + COA selectors fixed:
//    The BelongsToCompany trait auto-registers CompanyScope as a global
//    scope on Currency and ChartOfAccount. That scope unconditionally
//    filters every query to company_id = current company, with no
//    exception for company_id = NULL (system/shared) rows — even when
//    a local scope like forCompany() explicitly asks for NULL-or-mine.
//    Local + global scopes are ANDed, so the NULL rows were silently
//    dropped before reaching either dropdown. Fix: call
//    withoutGlobalScopes() before the manual company filter in both
//    activeCurrencies() and od223001Accounts() — the same pattern
//    already used in CurrencyService and ChartOfAccountService.
//
//  v1.5 — index() now also passes 'stats' (CleanOverdraftService::
//    stats()) alongside the paginated 'facilities', for the new
//    CleanOverdraftIndex.vue KPI row. $filters extracted once and
//    reused for both, so the KPI cards always match the active
//    bank/status filter shown in the list below them.
//
//  v1.6 — Added bankIndex(). One Vue page (CleanOverdraftIndex)
//    now serves two contexts: the portfolio view (index(), every
//    bank) and a single bank's view (bankIndex(), bank_id forced).
//    Reusing one page instead of building a second avoids the
//    same currency/COA-style bugs having to be fixed twice — and
//    this pattern will repeat for the other 6 facility types as
//    each gets its own dedicated build.
//
//  v1.7 — Facility Renewal. Added renew(), deleteRenewal(), destroy().
//    index()/bankIndex()/edit() now eager-load termsHistories() so
//    the Vue page can show the chapter timeline and drive the
//    Renew/Delete buttons without a second request.
//
//  @layer    Controller
//  @depends  CleanOverdraftService, CreditFacility, Bank
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\CleanOverdraftRequest;
use App\Http\Requests\FinancialInstitutions\RenewCleanOverdraftRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CleanOverdraftController extends Controller
{
    public function __construct(
        private CleanOverdraftService $service,
        private CurrencyService $currencyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CleanOverdraftIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'    => $filters,
        ]);
    }

    // ── Bank-Scoped Index ────────────────────────────────────────────

    /**
     * Same listing as index(), permanently filtered to one bank.
     * Reached from that bank's own "Credit Facilities" menu on the
     * Banks page. The 'bank' prop tells the Vue page to hide the
     * Bank column and the bank filter (every row is already this
     * bank) and skip the bank-picker on "+ New Facility", since the
     * bank is already known.
     */
    public function bankIndex(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        $filters = $request->only('status');
        $filters['bank_id'] = $bank->id;

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CleanOverdraftIndex', [
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

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CleanOverdraftForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223001Accounts($companyId),
            'bankAccounts'          => $this->activeCurrentAccountsForBank($companyId, $bank->id),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(CleanOverdraftRequest $request)
    {
        $data = $request->validated();
        $this->service->create($this->companyId($request), $data, $request->user());

        return redirect()
            ->route('fi.facilities.clean-od.index')
            ->with('success', 'Clean Overdraft facility created successfully.');
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'clean_od', 404);

        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/CleanOverdraftForm', [
            'facility' => $facility->load([
                'bank:id,name_en,name_ar',
                'currency:id,code',
                'withdrawals' => fn ($q) => $q->where('is_opening_balance', true)
                                              ->orderBy('due_date'),
                'termsHistories',
            ]),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223001Accounts($companyId),
            'bankAccounts'          => $this->activeCurrentAccountsForBank($companyId, $facility->bank_id),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(CleanOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'clean_od', 404);

        $data = $request->validated();
        $this->service->update($this->companyId($request), $facility, $data, $request->user());

        return redirect()
            ->route('fi.facilities.clean-od.index')
            ->with('success', 'Clean Overdraft facility updated successfully.');
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renew(RenewCleanOverdraftRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'clean_od', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'clean_od', 404);

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
        abort_if($facility->facility_type !== 'clean_od', 404);

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
    // functionalCurrencyId(), which every facility controller now
    // shares instead of each carrying its own copy.

    /**
     * COA accounts: 223001 and all sub-accounts.
     * Uses scopeForCompany() — covers system accounts (company_id = NULL)
     * AND company-specific accounts. Postable + active only.
     *
     * BUG FIX: column is 'code' not 'account_code'.
     * BUG FIX: must use scopeForCompany() not where('company_id') alone
     *          because system COA accounts have company_id = NULL.
     */
    private function od223001Accounts(int $companyId): \Illuminate\Support\Collection
    {
        // BUG FIX (v1.4): same root cause as activeCurrencies() above.
        // 223001 and its sub-accounts can carry company_id = NULL (the
        // standard COA template shared by every company). The global
        // CompanyScope filters every query to company_id = current
        // company with no NULL exception, so forCompany() alone never
        // actually saw the system rows. withoutGlobalScopes() first,
        // matching ChartOfAccountService::postableForDropdown().
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '223001%')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

    /**
     * TRS-001 fix (8 Sep 2026) — active Current accounts at this bank,
     * for the new "Linked Bank Account" field. A Clean Overdraft draws
     * against a real current account, so the picker is scoped to the
     * same bank the facility itself belongs to; the frontend narrows
     * further by the selected currency, matching CleanOverdraftRequest's
     * own validation scope.
     */
    private function activeCurrentAccountsForBank(int $companyId, int $bankId): \Illuminate\Support\Collection
    {
        return \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)
            ->where('bank_id', $bankId)
            ->current()
            ->active()
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_number', 'currency_id']);
    }
}
