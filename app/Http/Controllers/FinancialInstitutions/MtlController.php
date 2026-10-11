<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlController
//
//  HTTP layer for Medium Term Loan (facility_type = 'mtl'). Zero
//  business logic — delegated to MtlService. Same dual-mode pattern
//  as every other facility type, plus schedule-line and settlement
//  endpoints (the two things genuinely unique to MTL).
//
//  @layer    Controller
//  @depends  MtlService, CreditFacility, MtlScheduleLine, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\ImportMtlScheduleRequest;
use App\Http\Requests\FinancialInstitutions\MtlRequest;
use App\Http\Requests\FinancialInstitutions\RecordMtlSettlementRequest;
use App\Http\Requests\FinancialInstitutions\SaveMtlScheduleRequest;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\MtlService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MtlController extends Controller
{
    public function __construct(
        private MtlService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only('bank_id', 'status');

        return Inertia::render('FinancialInstitutions/Banks/Facilities/MtlIndex', [
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

        return Inertia::render('FinancialInstitutions/Banks/Facilities/MtlIndex', [
            'facilities' => $this->service->paginate($companyId, $filters),
            'stats'      => $this->service->stats($companyId, $filters),
            'bank'       => $bank->only(['id', 'name_en', 'name_ar']),
            'filters'    => $filters,
        ]);
    }

    public function create(Request $request, Bank $bank)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/MtlForm', [
            'facility'              => null,
            'bank'                  => $bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            // TRS-002 — a NEW loan is a payment source, so the form has
            // to offer the bank's current accounts to link it to.
            'bankAccounts'          => $this->activeCurrentAccountsForBank($companyId, $bank->id),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    public function store(MtlRequest $request)
    {
        $data = $request->validated();
        $facility = $this->service->create($this->companyId($request), $data, $request->user());

        return redirect()->route('fi.facilities.mtl.index')
            ->with('success', 'Medium Term Loan created successfully.');
    }

    public function edit(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'mtl', 404);
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/MtlForm', [
            'facility'              => $facility->load(['bank', 'currency']),
            'bank'                  => $facility->bank->only(['id', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'bankAccounts'          => $this->activeCurrentAccountsForBank($companyId, $facility->bank_id),
            'companyBaseCurrencyId' => $this->currencyService->functionalCurrencyId($companyId),
        ]);
    }

    public function update(MtlRequest $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'mtl', 404);

        $data = $request->validated();
        $this->service->update($this->companyId($request), $facility, $data, $request->user());

        return redirect()->route('fi.facilities.mtl.index')
            ->with('success', 'Medium Term Loan updated successfully.');
    }

    // ── Loan Schedule ─────────────────────────────────────────────

    public function schedule(Request $request, CreditFacility $facility)
    {
        abort_if($facility->facility_type !== 'mtl', 404);

        return Inertia::render(
            'FinancialInstitutions/Banks/Facilities/MtlScheduleIndex',
            $this->service->scheduleForFacility($facility)
        );
    }

    public function saveSchedule(SaveMtlScheduleRequest $request, CreditFacility $facility)
    {
        $data = $request->validated();

        try {
            $this->service->saveSchedule($facility, $data['lines'], $request->user());
            $warnings = $this->service->takeInterestWarnings();
            $back = back()->with('success', 'Loan schedule saved successfully.');
            return $warnings ? $back->with('warning', implode(' | ', array_slice($warnings, 0, 5))) : $back;
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function importSchedule(ImportMtlScheduleRequest $request)
    {
        $rows = $this->service->parseScheduleExcel($request->file('file'));
        return response()->json(['rows' => $rows]);
    }

    // ── Settlement ────────────────────────────────────────────────

    public function settlementForm(Request $request, MtlScheduleLine $scheduleLine)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/MtlSettlementForm', [
            'scheduleLine'  => $scheduleLine->load('creditFacility:id,name,currency_id', 'creditFacility.currency:id,code'),
            'settledAmount'   => $scheduleLine->settledAmount(),
            'remainingAmount' => $scheduleLine->remainingAmount(),
            'settlements'   => $scheduleLine->settlements()->with('currentAccount:id,account_number')->get(),
            'currentAccounts' => BankAccount::where('company_id', $companyId)
                ->where('is_active', true)->where('account_type', 'current')
                ->with('bank:id,name_en')->get(['id', 'bank_id', 'account_number', 'currency_id']),
        ]);
    }

    public function recordSettlement(RecordMtlSettlementRequest $request, MtlScheduleLine $scheduleLine)
    {
        $data = $request->validated();

        $this->service->recordSettlement($scheduleLine, $data, $request->user());

        return back()->with('success', 'Settlement recorded successfully.');
    }

    public function deleteSettlement(Request $request, \App\Models\FinancialInstitutions\MtlSettlement $settlement)
    {
        $this->service->deleteSettlement($settlement, $request->user());
        return back()->with('success', 'Settlement removed.');
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * The bank's own active current accounts, for the "Linked Bank
     * Account" picker. Same helper and same shape as
     * CleanOverdraftController's — a facility links to a real account
     * at the same bank, in the same currency.
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


    // activeCurrencies() / baseCurrencyId() removed (July 2026 audit) —
    // consolidated into CurrencyService::activeCurrenciesOrdered() /
    // functionalCurrencyId().

}
