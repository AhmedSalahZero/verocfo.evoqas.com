<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcIssuanceController
//
//  HTTP layer for individual LC issuances. Zero business logic — all
//  delegated to LetterOfCreditIssuanceService. Same shape as
//  LgIssuanceController: index tabbed by LC Type, Set to Draft /
//  Delete / per-action Undo, comment-only update on a locked posted
//  issuance vs. full update() on a reopened draft.
//
//  @layer    Controller
//  @depends  LetterOfCreditIssuanceService, LetterOfCreditIssuance,
//            Bank, FacilityLcType, CreditFacility, FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\LcIssuanceExpenseRequest;
use App\Http\Requests\FinancialInstitutions\LcIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\MarkLcIssuanceAsPaidRequest;
use App\Http\Requests\FinancialInstitutions\ReleaseLcCashCoverRequest;
use App\Http\Requests\FinancialInstitutions\SettleLcIssuanceRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLcType;
use App\Models\FinancialInstitutions\LcIssuanceExpense;
use App\Models\FinancialInstitutions\LetterOfCreditIssuance;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\LetterOfCreditIssuanceService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LcIssuanceController extends Controller
{
    public function __construct(
        private LetterOfCreditIssuanceService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── Index (tabbed by LC Type) ────────────────────────────────

    public function index(Request $request, string $lcType = 'Sight LC')
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('start_date', 'end_date');

        return Inertia::render('FinancialInstitutions/Treasury/LcIssuanceIndex', [
            'issuances'    => $this->service->paginate($companyId, $lcType, $filters),
            'stats'        => $this->service->stats($companyId, $lcType, $filters),
            'activeLcType' => $lcType,
            'lcTypes'      => FacilityLcType::DEFAULT_TYPES,
            'filters'      => $filters,
            'bankAccounts' => \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)
                ->where('is_active', true)->with('bank:id,name_en')
                ->get(['id', 'bank_id', 'account_number', 'account_type']),
            'expenseAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request, string $issuanceType)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/LcIssuanceForm', [
            'issuance'       => null,
            'issuanceType'   => $issuanceType,
            'banks'          => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'     => $this->currencyService->activeCurrenciesOrdered($companyId),
            'lcFacilities'   => $issuanceType === 'facility' ? $this->activeLcFacilities($companyId) : [],
            'bankAccounts'   => $this->activeBankAccounts($companyId),
            'defaultLcTypes' => FacilityLcType::DEFAULT_TYPES,
            'initialSuppliers' => $this->service->eligibleSuppliers($companyId),
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ]);
    }

    public function store(LcIssuanceRequest $request)
    {
        try {
            $issuance = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('fi.lc-issuance.index', $issuance->lc_type)
                ->with('success', 'Letter of Credit issuance created successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, LetterOfCreditIssuance $issuance)
    {
        $companyId = $this->companyId($request);

        $issuance->load([
            'bank', 'currency', 'supplier', 'creditFacility', 'purchaseOrder', 'supplierBill',
            'history' => fn ($q) => $q->orderByDesc('id'),
            'history.journalEntry.lines.chartOfAccount:id,code,name_en',
            'history.undoneBy:id,name', 'history.createdBy:id,name', 'history.supplierBill:id,bill_code',
            'movements' => fn ($q) => $q->orderByDesc('id'),
            'movements.postedJournalEntry.lines.chartOfAccount:id,code,name_en',
            'expenses' => fn ($q) => $q->orderByDesc('id'),
        ]);

        $undoable = $issuance->undoableHistoryEntry();

        return Inertia::render('FinancialInstitutions/Treasury/LcIssuanceForm', [
            'issuance'       => $issuance,
            'issuanceType'   => $issuance->issuance_type,
            'banks'          => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'     => $this->currencyService->activeCurrenciesOrdered($companyId),
            'lcFacilities'   => $issuance->issuance_type === 'facility' ? $this->activeLcFacilities($companyId) : [],
            'bankAccounts'   => $this->activeBankAccounts($companyId),
            'defaultLcTypes' => FacilityLcType::DEFAULT_TYPES,
            'initialSuppliers' => $this->service->eligibleSuppliers($companyId),
            'expenseAccounts'  => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
            'periodClosed'    => $this->fiscalYearService->isPeriodClosed($companyId, (string) $issuance->issuance_date),
            'canSetToDraft'   => $issuance->isPosted() && $issuance->isPristine(),
            'undoableEvent'   => $undoable ? ['id' => $undoable->id, 'event_type' => $undoable->event_type] : null,
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ]);
    }

    public function update(LcIssuanceRequest $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->update($issuance, $request->validated(), $request->user());
            return redirect()->route('fi.lc-issuance.index', $issuance->lc_type)
                ->with('success', "LC Issuance [{$issuance->lc_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ── Set to Draft / Delete ────────────────────────────────────────

    public function setToDraft(Request $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->setToDraft($issuance, $request->user());
            return redirect()->route('fi.lc-issuance.edit', $issuance)->with('success', "LC Issuance [{$issuance->lc_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $lcType = $issuance->lc_type;
            $code   = $issuance->lc_code;
            $this->service->delete($issuance, $request->user());
            return redirect()->route('fi.lc-issuance.index', $lcType)->with('success', "LC Issuance [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Mark as Paid / Undo ──────────────────────────────────────────

    public function markAsPaid(MarkLcIssuanceAsPaidRequest $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->markAsPaid($issuance, $request->validated(), $request->user());
            return back()->with('success', 'LC marked as paid successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoMarkAsPaid(Request $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->undoMarkAsPaid($issuance, $request->user());
            return back()->with('success', 'Mark as Paid undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Close unused LC & release cash cover / Undo (H-21) ───────────

    public function releaseCashCover(ReleaseLcCashCoverRequest $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->releaseCashCoverOnExpiry($issuance, $request->validated(), $request->user());
            return back()->with('success', 'LC closed and its cash cover released.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoCashCoverRelease(Request $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->undoCashCoverRelease($issuance, $request->user());
            return back()->with('success', 'Cash cover release undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Settlement / Undo ────────────────────────────────────────────

    public function settle(SettleLcIssuanceRequest $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->settle($issuance, $request->validated(), $request->user());
            $warnings = $this->service->takeInterestWarnings();
            $back = back()->with('success', 'Settlement recorded successfully.');
            return $warnings ? $back->with('warning', implode(' | ', $warnings)) : $back;
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoSettlement(Request $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->undoSettlement($issuance, $request->user());
            return back()->with('success', 'Settlement undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Expenses ──────────────────────────────────────────────────────

    public function storeExpense(LcIssuanceExpenseRequest $request, LetterOfCreditIssuance $issuance)
    {
        try {
            $this->service->addExpense($issuance, $request->validated(), $request->user());
            return back()->with('success', 'Expense added successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateExpense(LcIssuanceExpenseRequest $request, LcIssuanceExpense $expense)
    {
        try {
            $this->service->updateExpense($expense, $request->validated(), $request->user());
            return back()->with('success', 'Expense updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroyExpense(Request $request, LcIssuanceExpense $expense)
    {
        try {
            $this->service->deleteExpense($expense, $request->user());
            return back()->with('success', 'Expense deleted successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── JSON lookups ──────────────────────────────────────────────

    public function eligibleSuppliers(Request $request)
    {
        return response()->json($this->service->eligibleSuppliers($this->companyId($request)));
    }

    public function eligiblePurchaseOrders(Request $request, int $partnerId)
    {
        return response()->json($this->service->eligiblePurchaseOrders($this->companyId($request), $partnerId));
    }

    public function eligibleSupplierBills(Request $request, LetterOfCreditIssuance $issuance)
    {
        return response()->json($this->service->eligibleSupplierBills(
            $this->companyId($request),
            $issuance->supplier_partner_id,
            $issuance->purchase_order_id
        ));
    }

    public function facilityRoomSummary(Request $request, CreditFacility $facility)
    {
        return response()->json($this->service->facilityRoomSummary($this->companyId($request), $facility->id));
    }

    public function exchangeRateLookup(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencyId = $request->integer('currency_id');
        $date = $request->string('date')->toString() ?: now()->toDateString();

        $rate = \App\Models\ExchangeRate::rateFor($companyId, $currencyId, $date);

        return response()->json(['rate' => $rate]);
    }

    // ── Settlements (standalone, cross-LC — Mahmoud's request) ────────

    public function settlementsIndex(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('start_date', 'end_date', 'bank_id');

        return Inertia::render('FinancialInstitutions/Treasury/LcSettlementsIndex', [
            'settlements' => $this->service->paginateSettlements($companyId, $filters),
            'stats'       => $this->service->settlementsStats($companyId, $filters),
            'filters'     => $filters,
            'banks'       => Bank::active()->orderBy('name_en')->get(['id', 'name_en']),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────

    private function activeLcFacilities(int $companyId)
    {
        return CreditFacility::where('company_id', $companyId)
            ->where('facility_type', 'import_lc')
            ->where('status', 'active')
            ->with('lcTypes')
            ->get(['id', 'name', 'bank_id', 'facility_limit', 'currency_id', 'lc_security_type']);
    }

    private function activeBankAccounts(int $companyId)
    {
        return \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'account_number', 'account_type', 'currency_id']);
    }
}
