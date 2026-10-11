<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfGuaranteeController
//
//  HTTP layer for Letter of Guarantee (227 + 138001) facility
//  AGREEMENTS. Zero business logic — all delegated to
//  LetterOfGuaranteeService.
//
//  This is an agreement-level controller only. It creates and
//  edits the facility + its LG-type rate card (facility_lg_types).
//  Individual LG issuances (beneficiary, amount, expiry) are a
//  separate future controller, built once the LG Issuance form is
//  designed with Mahmoud.
//
//  Same dual-mode pattern as every other facility type — one Vue
//  page (LetterOfGuaranteeIndex) serves both the portfolio view
//  (index()) and a single bank's view (bankIndex()).
//
//  No COA account selector on this form (unlike Clean OD / CP OD) —
//  confirmed with Mahmoud that coa_account_id is an Issuance-layer
//  concern for LG, not an Agreement-layer one. See migration
//  000065 and LetterOfGuaranteeService class header.
//
//  @layer    Controller
//  @depends  LetterOfGuaranteeService, CreditFacility, Bank
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\LetterOfGuaranteeRequest;
use App\Http\Requests\FinancialInstitutions\RenewLetterOfGuaranteeRequest;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\LetterOfGuaranteeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LetterOfGuaranteeController extends Controller
{
    public function __construct(
        private LetterOfGuaranteeService $service,
        private CurrencyService $currencyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'    => $filters,
            'commissionIntervals' => FacilityLgType::COMMISSION_INTERVALS,
        ]);
    }

    // ── Bank-Scoped Index ────────────────────────────────────────────

    public function bankIndex(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        $filters = $request->only('status');
        $filters['bank_id'] = $bank->id;

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'bank'       => $bank->only(['id', 'name_en', 'name_ar']),
            'filters'    => $filters,
            'commissionIntervals' => FacilityLgType::COMMISSION_INTERVALS,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'defaultLgTypes'        => FacilityLgType::DEFAULT_TYPES,
            'commissionIntervals'   => FacilityLgType::COMMISSION_INTERVALS,
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(LetterOfGuaranteeRequest $request)
    {
        $data = $request->validated();
        $this->service->create($this->companyId($request), $data, $request->user());

        return redirect()
            ->route('fi.facilities.lg.index')
            ->with('success', 'Letter of Guarantee facility created successfully.');
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'letter_guarantee', 404);

        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeForm', [
            'facility' => $facility->load([
                'bank:id,name_en,name_ar',
                'currency:id,code',
                'lgTypes',
            ]),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'defaultLgTypes'        => FacilityLgType::DEFAULT_TYPES,
            'commissionIntervals'   => FacilityLgType::COMMISSION_INTERVALS,
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(LetterOfGuaranteeRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'letter_guarantee', 404);

        $data = $request->validated();
        $this->service->update($this->companyId($request), $facility, $data, $request->user());

        return redirect()
            ->route('fi.facilities.lg.index')
            ->with('success', 'Letter of Guarantee facility updated successfully.');
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * IMPORTANT — this renews the LG FACILITY (agreement), not an
     * individual LG issuance. See LgIssuanceController::renew() for
     * that separate action, reachable from the LG Issuance list page.
     */
    public function renew(RenewLetterOfGuaranteeRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'letter_guarantee', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'letter_guarantee', 404);

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
}
