<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuanceController
//
//  HTTP layer for individual LG issuances. Zero business logic —
//  all delegated to LgIssuanceService. Covers all 3 issuance types
//  (facility / cash_cover / time_deposit) through one set of routes
//  — the Vue form switches its own layout based on issuance_type,
//  same way the create/edit pages share one component per Rule 2.
//
//  Index is tabbed by LG Type (Bid Bond / Final LG / Advanced
//  Payment LG / Performance LG), not by issuance type — matches the
//  reference image. lgType arrives as a route parameter.
//
//  UPDATE (August 2026 — correction-everywhere fix, confirmed with
//  Mahmoud): the form is no longer permanently frozen after creation.
//  - update()          → PUT, full LgIssuanceRequest — only reachable
//                         while the issuance is a draft (setToDraft()
//                         is the only way it gets there).
//  - updateComment()    → PATCH .../comment, comment-only — what
//                         update() used to be, kept for annotating a
//                         posted (locked) issuance without reopening it.
//  - setToDraft()       → reopens a posted, pristine issuance.
//  - destroy()          → deletes a draft (only reachable post-
//                         setToDraft(), so it's a harmless empty shell).
//  - undoRenewal()/undoDecrease()/undoCancel()/undoLiquidate() →
//                         corrects one specific lifecycle action
//                         without touching the original issuance —
//                         see LgIssuanceService class header.
//
//  @layer    Controller
//  @depends  LgIssuanceService, LgIssuance, Bank, Currency,
//            FacilityLgType, CreditFacility, FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\CancelLgIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\DecreaseLgIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\LgIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\LiquidateLgIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\RenewLgIssuanceRequest;
use App\Http\Requests\FinancialInstitutions\UpdateLgIssuanceCommentRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\LgIssuanceService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LgIssuanceController extends Controller
{
    public function __construct(
        private LgIssuanceService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── Index (tabbed by LG Type) ────────────────────────────────

    public function index(Request $request, string $lgType = 'Bid Bond')
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('start_date', 'end_date');

        return Inertia::render('FinancialInstitutions/Treasury/LgIssuanceIndex', [
            'issuances'    => $this->service->paginate($companyId, $lgType, $filters),
            'stats'        => $this->service->stats($companyId, $lgType, $filters),
            'activeLgType' => $lgType,
            'lgTypes'      => FacilityLgType::DEFAULT_TYPES,
            'filters'      => $filters,
            'bankAccounts' => \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)
                ->where('is_active', true)
                ->with('bank:id,name_en')
                ->get(['id', 'bank_id', 'account_number', 'account_type']),
            // Fix (July 2026 audit): needed for the Liquidate modal —
            // the user picks which expense/loss account absorbs the
            // claim (liquidation_expense_account_id). Postable only.
            'expenseAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request, string $issuanceType)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/LgIssuanceForm', [
            'issuance'       => null,
            'issuanceType'   => $issuanceType,
            'banks'          => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'     => $this->currencyService->activeCurrenciesOrdered($companyId),
            'lgFacilities'   => $issuanceType === 'facility' ? $this->activeLgFacilities($companyId) : [],
            'bankAccounts'   => $this->activeBankAccounts($companyId),
            'defaultLgTypes' => FacilityLgType::DEFAULT_TYPES,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(LgIssuanceRequest $request)
    {
        try {
            $data = $request->validated();
            $issuance = $this->service->create($this->companyId($request), $data, $request->user());

            return redirect()
                ->route('fi.lg-issuance.index', $issuance->lg_type)
                ->with('success', 'Letter of Guarantee issuance created successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, LgIssuance $issuance)
    {
        $companyId = $this->companyId($request);

        $issuance->load([
            'bank', 'currency', 'beneficiary', 'creditFacility',
            'history' => fn ($q) => $q->orderByDesc('id'),
            'history.journalEntry.lines.chartOfAccount:id,code,name_en',
            'history.undoneBy:id,name',
            'history.createdBy:id,name',
            'movements' => fn ($q) => $q->orderByDesc('id'),
            'movements.postedJournalEntry.lines.chartOfAccount:id,code,name_en',
        ]);

        $undoable = $issuance->undoableHistoryEntry();

        return Inertia::render('FinancialInstitutions/Treasury/LgIssuanceForm', [
            'issuance'       => $issuance,
            'issuanceType'   => $issuance->issuance_type,
            'banks'          => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'     => $this->currencyService->activeCurrenciesOrdered($companyId),
            'lgFacilities'   => $issuance->issuance_type === 'facility' ? $this->activeLgFacilities($companyId) : [],
            'bankAccounts'   => $this->activeBankAccounts($companyId),
            'defaultLgTypes' => FacilityLgType::DEFAULT_TYPES,
            // Fix (August 2026): the Beneficiary dropdown's option
            // list used to only load via a watch() on LG Type, which
            // never fires on initial page load in edit mode (the
            // field never "changes" — it arrives already set) —
            // the select had no option text to show even though
            // beneficiary_partner_id was correct. Passed directly
            // here instead so the form always has it, no watch
            // timing dependency.
            'initialBeneficiaries' => $this->service->eligibleBeneficiaries($companyId, $issuance->lg_type),
            'periodClosed'    => $this->fiscalYearService->isPeriodClosed($companyId, (string) $issuance->issuance_date),
            'canSetToDraft'   => $issuance->isPosted() && $issuance->isPristine(),
            'undoableEvent'   => $undoable ? ['id' => $undoable->id, 'event_type' => $undoable->event_type] : null,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    /**
     * Only reachable when the issuance is a draft — the only way it
     * becomes one is setToDraft(), which has already reversed
     * everything real it posted. Re-validates and re-posts through
     * LgIssuanceService::update(), same shape as store().
     */
    public function update(LgIssuanceRequest $request, LgIssuance $issuance)
    {
        try {
            $this->service->update($issuance, $request->validated(), $request->user());
            return redirect()->route('fi.lg-issuance.index', $issuance->lg_type)
                ->with('success', "LG Issuance [{$issuance->lg_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Comment-only annotation on a POSTED (locked) issuance — what
     * update() used to be before the draft workflow existed. Core
     * fields stay locked while posted; use Set to Draft to actually
     * edit them.
     */
    public function updateComment(UpdateLgIssuanceCommentRequest $request, LgIssuance $issuance)
    {
        $issuance->update($request->validated());

        return redirect()->route('fi.lg-issuance.index', $issuance->lg_type)
            ->with('success', 'Updated.');
    }

    // ── Set to Draft / Delete ────────────────────────────────────────

    public function setToDraft(Request $request, LgIssuance $issuance)
    {
        try {
            $this->service->setToDraft($issuance, $request->user());
            return redirect()->route('fi.lg-issuance.edit', $issuance)->with('success', "LG Issuance [{$issuance->lg_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, LgIssuance $issuance)
    {
        try {
            $lgType = $issuance->lg_type;
            $code   = $issuance->lg_code;
            $this->service->delete($issuance, $request->user());
            return redirect()->route('fi.lg-issuance.index', $lgType)->with('success', "LG Issuance [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Undo (per lifecycle action) ──────────────────────────────────

    public function undoRenewal(Request $request, LgIssuance $issuance)
    {
        try {
            $this->service->undoRenewal($issuance, $request->user());
            return back()->with('success', 'Renewal undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoDecrease(Request $request, LgIssuance $issuance)
    {
        try {
            $this->service->undoDecrease($issuance, $request->user());
            return back()->with('success', 'Decrease undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoCancel(Request $request, LgIssuance $issuance)
    {
        try {
            $this->service->undoCancel($issuance, $request->user());
            return back()->with('success', 'Cancellation undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoLiquidate(Request $request, LgIssuance $issuance)
    {
        try {
            $this->service->undoLiquidate($issuance, $request->user());
            return back()->with('success', 'Liquidation undone.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Lifecycle actions ────────────────────────────────────────

    public function renew(RenewLgIssuanceRequest $request, LgIssuance $issuance)
    {
        try {
            $this->service->renew($issuance, $request->validated(), $request->user());
            // Redirect to the Edit/detail page (not back() to the list) —
            // this is where the History table lives, with its Undo button
            // for the renewal just performed. Without this, a user who
            // renewed by mistake had no visible way back to correct it.
            return redirect()->route('fi.lg-issuance.edit', $issuance)
                ->with('success', "LG renewed successfully. You can undo this renewal below if needed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function decrease(DecreaseLgIssuanceRequest $request, LgIssuance $issuance)
    {
        try {
            $this->service->decrease($issuance, $request->validated(), $request->user());
            return back()->with('success', 'LG amount decreased successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(CancelLgIssuanceRequest $request, LgIssuance $issuance)
    {
        try {
            $this->service->cancel($issuance, $request->validated(), $request->user());
            return back()->with('success', 'LG cancelled successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function liquidate(LiquidateLgIssuanceRequest $request, LgIssuance $issuance)
    {
        try {
            $this->service->liquidate($issuance, $request->validated(), $request->user());
            return back()->with('success', 'LG liquidated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── JSON lookups ──────────────────────────────────────────────

    public function eligibleBeneficiaries(Request $request, string $lgType)
    {
        return response()->json(
            $this->service->eligibleBeneficiaries($this->companyId($request), $lgType)
        );
    }

    public function eligibleContracts(Request $request, int $partnerId)
    {
        return response()->json(
            $this->service->eligibleContracts($this->companyId($request), $partnerId)
        );
    }

    public function facilityRoomSummary(Request $request, CreditFacility $facility)
    {
        return response()->json(
            $this->service->facilityRoomSummary($this->companyId($request), $facility->id)
        );
    }

    // ── Private helpers ───────────────────────────────────────────

    // activeCurrencies() removed (July 2026 audit) — consolidated into
    // CurrencyService::activeCurrenciesOrdered(), which also adds
    // functional-currency-first ordering this version didn't have.

    private function activeLgFacilities(int $companyId)
    {
        return CreditFacility::where('company_id', $companyId)
            ->where('facility_type', 'letter_guarantee')
            ->where('status', 'active')
            ->with('lgTypes')
            ->get(['id', 'name', 'bank_id', 'facility_limit', 'currency_id']);
    }

    private function activeBankAccounts(int $companyId)
    {
        return \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'account_number', 'account_type', 'currency_id']);
    }

}
