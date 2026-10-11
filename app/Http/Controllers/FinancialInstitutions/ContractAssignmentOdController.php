<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ContractAssignmentOdController
//
//  HTTP layer for OD Against Contract/SO Assignment (223003)
//  facility contracts. Zero business logic — all delegated to
//  ContractAssignmentOdService.
//
//  Same established patterns as CleanOverdraftController and
//  CommercialPaperOverdraftController:
//    - COA lookup: withoutGlobalScopes() + forCompany() (system
//      shared accounts have company_id = NULL — global scope drops
//      them without this; see Known Recurring Bug in project bible)
//    - Currency lookup: via company_active_currencies pivot, same
//      withoutGlobalScopes() protection
//    - Dual-mode index: one Vue page, one controller action per mode
//      (portfolio index + bank-scoped bankIndex)
//    - Type guard on edit/update: abort_if wrong facility_type
//
//  New routes not present on other facility controllers:
//    eligibleCollateral() — GET JSON endpoint for the modal's
//      collateral and partner dropdowns. Returns live data so the
//      modal never shows already-pledged or expired contracts.
//    addAssignment()      — POST — modal Confirm button
//    removeAssignment()   — DELETE — modal row remove button
//
//  @layer    Controller
//  @depends  ContractAssignmentOdService, CreditFacility, Bank,
//            FacilityContractAssignment
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\ContractAssignmentOdAssignmentRequest;
use App\Http\Requests\FinancialInstitutions\ContractAssignmentOdFacilityRequest;
use App\Http\Requests\FinancialInstitutions\RenewContractAssignmentOdRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityContractAssignment;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\ContractAssignmentOdService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContractAssignmentOdController extends Controller
{
    public function __construct(
        private ContractAssignmentOdService $service,
        private CurrencyService $currencyService,
    ) {}

    // ── Portfolio Index ───────────────────────────────────────────

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/ContractAssignmentOdIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
            'filters'    => $filters,
        ]);
    }

    // ── Bank-Scoped Index ─────────────────────────────────────────

    public function bankIndex(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('status');
        $filters['bank_id'] = $bank->id;

        return Inertia::render('FinancialInstitutions/Banks/Facilities/ContractAssignmentOdIndex', [
            'facilities' => $this->service->paginate($companyId, $filters, $bank->id),
            'stats'      => $this->service->stats($companyId, $filters, $bank->id),
            'bank'       => $bank->only(['id', 'name_en', 'name_ar']),
            'filters'    => $filters,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/ContractAssignmentOdForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223003Accounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(ContractAssignmentOdFacilityRequest $request)
    {
        $data      = $request->validated();
        $bank      = Bank::findOrFail($data['bank_id']);
        $companyId = $this->companyId($request);

        try {
            $this->service->create($companyId, $bank, $data, $request->user());

            // Redirect back to the bank-scoped index so the user stays in context.
            return redirect()
                ->route('fi.banks.contract-od.index', $bank->id)
                ->with('success', 'OD Against Contract Assignment facility created successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/ContractAssignmentOdForm', [
            'facility'              => $this->service->withEditRelations($facility),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'coaAccounts'           => $this->od223003Accounts($companyId),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(ContractAssignmentOdFacilityRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);

        $data = $request->validated();

        try {
            $this->service->update($this->companyId($request), $facility, $data, $request->user());

            // Redirect back to the bank-scoped index so the user stays in context.
            return redirect()
                ->route('fi.banks.contract-od.index', $facility->bank_id)
                ->with('success', 'OD Against Contract Assignment facility updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Eligible Collateral (modal JSON endpoint) ─────────────────

    /**
     * Returns eligible Projects/SOs for the assignment modal.
     * Optionally filtered by partner_id when the customer dropdown changes.
     * Also returns the eligible partners list for the customer dropdown.
     */
    public function eligibleCollateral(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id') ?: null;

        return response()->json([
            'partners'   => $this->service->eligiblePartners($companyId, $facility),
            'collateral' => $this->service->eligibleCollateral($companyId, $facility, $partnerId),
        ]);
    }

    // ── Add Assignment (modal Confirm) ────────────────────────────

    public function addAssignment(ContractAssignmentOdAssignmentRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);

        try {
            $assignment = $this->service->addAssignment(
                $this->companyId($request),
                $facility,
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'assignment' => $assignment,
                'message'    => 'Contract assigned successfully.',
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Remove Assignment (modal row delete) ──────────────────────

    public function removeAssignment(Request $request, FacilityContractAssignment $assignment)
    {
        try {
            $this->service->removeAssignment(
                $this->companyId($request),
                $assignment,
                $request->user()
            );

            return response()->json(['message' => 'Assignment removed successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renew(RenewContractAssignmentOdRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);

        try {
            $this->service->renewFacility($this->companyId($request), $facility, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['effective_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility renewed successfully.');
    }

    public function deleteRenewal(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'contract_so', 404);

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
        abort_if($facility->facility_type !== 'contract_so', 404);

        try {
            $this->service->delete($this->companyId($request), $facility, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Facility deleted successfully.');
    }

    // ── Private Helpers ───────────────────────────────────────────

    // activeCurrencies() / baseCurrencyId() removed (July 2026 audit) —
    // consolidated into CurrencyService::activeCurrenciesOrdered() /
    // functionalCurrencyId().

    /**
     * COA accounts: 223003 and all user-created sub-accounts.
     * withoutGlobalScopes() + forCompany() — same bug prevention as all
     * other COA selectors in this module. See Known Recurring Bug in bible.
     */
    private function od223003Accounts(int $companyId): \Illuminate\Support\Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '223003%')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

}