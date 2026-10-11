<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BuySellCurrencyController
//
//  HTTP layer for Buy/Sell Currency. Zero business logic — all
//  delegated to BuySellCurrencyService. Unlike Internal Money
//  Transfer, store()/update() always save a DRAFT only — post() is a
//  separate action the user takes once they've confirmed (or
//  overridden) the auto-pulled reference rates and reviewed the
//  computed FX gain/loss. Edit + Delete both only work on a draft;
//  a posted transaction's only path back to editable is "Set to
//  Draft" (setToDraft), same shape as Customer Payment.
//
//  Routes (under auth + active.company):
//    GET    /financial-institutions/buy-sell-currencies                          → index()
//    GET    /financial-institutions/buy-sell-currencies/create                   → create()
//    POST   /financial-institutions/buy-sell-currencies                         → store()
//    GET    /financial-institutions/buy-sell-currencies/{transaction}/edit       → edit()
//    PUT    /financial-institutions/buy-sell-currencies/{transaction}            → update()
//    POST   /financial-institutions/buy-sell-currencies/{transaction}/post       → post()
//    PATCH  /financial-institutions/buy-sell-currencies/{transaction}/set-to-draft → setToDraft()
//    DELETE /financial-institutions/buy-sell-currencies/{transaction}            → destroy()
//    GET    /financial-institutions/buy-sell-currencies-lookup/safe-accounts     → safeAccounts() (JSON)
//    GET    /financial-institutions/buy-sell-currencies-lookup/reference-rate    → referenceRate() (JSON)
//
//  @layer    Controller
//  @depends  BuySellCurrencyService, BuySellCurrency, BankAccount,
//            ChartOfAccount, CurrencyService, ExchangeRate
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\StoreBuySellCurrencyRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\ExchangeRate;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\BuySellCurrency;
use App\Services\CurrencyService;
use App\Services\FinancialInstitutions\BuySellCurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BuySellCurrencyController extends Controller
{
    public function __construct(
        private BuySellCurrencyService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Treasury/BuySellCurrencyIndex', [
            'transactions' => $this->service->paginate($this->companyId($request), $request->only('search', 'status')),
            'filters'      => $request->only('search', 'status'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('FinancialInstitutions/Treasury/BuySellCurrencyForm', $this->formProps($request) + ['mode' => 'create']);
    }

    public function store(StoreBuySellCurrencyRequest $request)
    {
        try {
            $transaction = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('fi.buy-sell-currencies.edit', $transaction->id)->with('success', "Buy/Sell Currency [{$transaction->transaction_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, BuySellCurrency $buySellCurrency)
    {
        return Inertia::render('FinancialInstitutions/Treasury/BuySellCurrencyForm', $this->formProps($request) + [
            'mode'  => 'edit',
            'model' => $buySellCurrency->load(['fromBankAccount.bank', 'fromSafeAccount', 'toBankAccount.bank', 'toSafeAccount', 'currencyToSell', 'currencyToBuy', 'journalEntry.lines.chartOfAccount']),
        ]);
    }

    public function update(StoreBuySellCurrencyRequest $request, BuySellCurrency $buySellCurrency)
    {
        try {
            $this->service->update($this->companyId($request), $buySellCurrency, $request->validated(), $request->user());
            return back()->with('success', "Buy/Sell Currency [{$buySellCurrency->transaction_code}] updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function post(Request $request, BuySellCurrency $buySellCurrency)
    {
        try {
            $this->service->post($buySellCurrency, $request->user(), $request->only('sell_reference_rate', 'buy_reference_rate', 'rate_override_reason'));
            return redirect()->route('fi.buy-sell-currencies.index')->with('success', "Buy/Sell Currency [{$buySellCurrency->transaction_code}] posted successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setToDraft(Request $request, BuySellCurrency $buySellCurrency)
    {
        try {
            $this->service->setToDraft($buySellCurrency, $request->user());
            return back()->with('success', "Buy/Sell Currency [{$buySellCurrency->transaction_code}] reopened as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, BuySellCurrency $buySellCurrency)
    {
        try {
            $code = $buySellCurrency->transaction_code;
            $this->service->delete($buySellCurrency, $request->user());
            return redirect()->route('fi.buy-sell-currencies.index')->with('success', "Buy/Sell Currency [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — this company's own 131xxx Safe sub-accounts, optionally filtered by currency. Same pattern as InternalMoneyTransferController::safeAccounts(). */
    public function safeAccounts(Request $request)
    {
        $companyId  = $this->companyId($request);
        $currencyId = $request->integer('currency_id') ?: null;

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->orderBy('code')->get(['id', 'code', 'name_en', 'currency_id']);

        return response()->json(['accounts' => $accounts])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Live lookup — the system reference rate for a currency on a
     * given date, so the form can preview the auto-pulled rate (and
     * the resulting fair value / plug) before the user commits to
     * post(). Read-only, never writes anything.
     */
    public function referenceRate(Request $request)
    {
        $companyId  = $this->companyId($request);
        $currencyId = $request->integer('currency_id');
        $date       = $request->input('date') ?: now()->toDateString();

        $functionalCurrencyId = (int) CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('is_functional', true)->value('currency_id');

        $rate = $currencyId === $functionalCurrencyId
            ? '1.000000'
            : ExchangeRate::rateFor($companyId, $currencyId, $date);

        return response()->json(['rate' => $rate])
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
                ->get(['id', 'bank_id', 'account_number', 'account_type', 'currency_id', 'coa_account_id']),
            'safeAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('code', 'like', '131%')->where('is_postable', true)
                ->orderBy('code')->get(['id', 'code', 'name_en', 'currency_id']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}
