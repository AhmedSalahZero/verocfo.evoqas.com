<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  InternalMoneyTransferController
//
//  HTTP layer for Internal Money Transfer. Zero business logic — all
//  delegated to InternalMoneyTransferService. record() posts
//  immediately (no draft-then-post), same shape as Customer/Supplier
//  Payment — so this has no edit()/update(), only Create + the
//  lifecycle actions (Confirm Received, Cancel) reachable from Index.
//
//  Routes (under auth + active.company):
//    GET    /financial-institutions/internal-money-transfers                        → index()
//    GET    /financial-institutions/internal-money-transfers/create                 → create()
//    POST   /financial-institutions/internal-money-transfers                        → store()
//    PATCH  /financial-institutions/internal-money-transfers/{transfer}/confirm     → confirmReceived()
//    PATCH  /financial-institutions/internal-money-transfers/{transfer}/cancel      → cancel()
//    GET    /financial-institutions/internal-money-transfers-lookup/safe-accounts   → safeAccounts() (JSON)
//
//  @layer    Controller
//  @depends  InternalMoneyTransferService, InternalMoneyTransfer,
//            BankAccount, ChartOfAccount, CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\StoreInternalMoneyTransferRequest;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\InternalMoneyTransfer;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InternalMoneyTransferController extends Controller
{
    public function __construct(
        private InternalMoneyTransferService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Treasury/InternalMoneyTransferIndex', [
            'transfers' => $this->service->paginate($this->companyId($request), $request->only('search', 'status')),
            'filters'   => $request->only('search', 'status'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Treasury/InternalMoneyTransferForm', $this->formProps($request));
    }

    public function store(StoreInternalMoneyTransferRequest $request)
    {
        try {
            $transfer = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('fi.internal-money-transfers.index')->with('success', "Internal Transfer [{$transfer->transfer_code}] posted successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function confirmReceived(Request $request, InternalMoneyTransfer $internalMoneyTransfer)
    {
        try {
            $this->service->confirmReceived($internalMoneyTransfer, $request->user(), $request->input('cleared_date'));
            return back()->with('success', "Internal Transfer [{$internalMoneyTransfer->transfer_code}] confirmed received.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, InternalMoneyTransfer $internalMoneyTransfer)
    {
        try {
            $this->service->cancel($internalMoneyTransfer, $request->user());
            return back()->with('success', "Internal Transfer [{$internalMoneyTransfer->transfer_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — this company's own 131xxx Safe sub-accounts, optionally filtered by currency. Same pattern as CustomerPaymentController::safeAccounts(). */
    public function safeAccounts(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencyId = $request->integer('currency_id') ?: null;

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->orderBy('code')->get(['id', 'code', 'name_en', 'currency_id']);

        return response()->json(['accounts' => $accounts])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'bankAccounts' => BankAccount::where('company_id', $companyId)
                ->where('is_active', true)
                ->with('bank:id,name_en')
                ->get(['id', 'bank_id', 'account_number', 'account_type', 'currency_id']),
            'safeAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('code', 'like', '131%')->where('is_postable', true)
                ->orderBy('code')->get(['id', 'code', 'name_en', 'currency_id']),
            // Facility Settlement Engine (Rule 34) — only OD-family
            // facilities can be a leg here; a facility with no linked
            // bank_account_id is excluded, since the service can't
            // record a drawdown/settlement against it (same guard the
            // service itself enforces).
            'facilities' => CreditFacility::where('company_id', $companyId)
                ->payableSource()   // TRS-002 — now includes an undrawn Medium-Term Loan
                ->whereNotNull('bank_account_id')
                ->with('bank:id,name_en')
                ->get(['id', 'bank_id', 'facility_reference', 'facility_type', 'currency_id', 'facility_limit', 'outstanding_balance']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}
