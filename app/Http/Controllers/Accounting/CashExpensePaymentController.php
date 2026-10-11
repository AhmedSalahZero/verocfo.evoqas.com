<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePaymentController
//
//  HTTP layer for Cash Expense Payment. Zero business logic —
//  delegated to CashExpensePaymentService. See migration 000177.
//
//  Same lifecycle shape as OtherPaymentController: record() posts
//  immediately (no draft stage on create); "Set to Draft" is the only
//  way to reopen a posted one for editing; delete() only ever
//  reachable on a draft; cancel() is the dead-end alternative.
//
//  Routes (under auth + active.company):
//    GET    /accounting/cash-expense-payments                                → index()
//    GET    /accounting/cash-expense-payments/create                        → create()
//    POST   /accounting/cash-expense-payments                                → store()
//    GET    /accounting/cash-expense-payments/{cashExpensePayment}/edit      → edit()
//    PUT    /accounting/cash-expense-payments/{cashExpensePayment}           → update()
//    DELETE /accounting/cash-expense-payments/{cashExpensePayment}           → destroy()
//    PATCH  /accounting/cash-expense-payments/{cashExpensePayment}/set-to-draft → setToDraft()
//    PATCH  /accounting/cash-expense-payments/{cashExpensePayment}/cancel    → cancel()
//    GET    /accounting/cash-expense-payments/partners                      → partners() (JSON)
//    GET    /accounting/cash-expense-payments/sources                       → sources() (JSON)
//    GET    /accounting/cash-expense-payments/safe-accounts                 → safeAccounts() (JSON)
//    GET    /accounting/cash-expense-payments/default-vat-rate              → defaultVatRate() (JSON)
//    GET    /accounting/cash-expense-payments/customers                    → customers() (JSON)
//    GET    /accounting/cash-expense-payments/projects-for-customer/{partnerId} → projectsForCustomer() (JSON)
//
//  MULTI-LINE (August 2026, see migration 000181) — expenseAccounts is
//  now a full preloaded list in formProps() (same pattern
//  SupplierBillController already uses for its own line table),
//  rather than a live-search endpoint — a plain <select> per line row
//  needs the whole list anyway, and requires_cost_center travels with
//  every row so the Vue form needs no extra round-trip to know a
//  line's Cost Center requirement.
//
//  @layer    Controller
//  @depends  CashExpensePaymentService, CashExpensePayment, CashExpensePaymentRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CashExpensePaymentRequest;
use App\Models\CashExpensePayment;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CompanyTaxSetting;
use App\Models\CostCenter;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Partner;
use App\Services\Accounting\CashExpensePaymentService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashExpensePaymentController extends Controller
{
    public function __construct(
        private CashExpensePaymentService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/CashExpensePayments/Index', [
            'cashExpensePayments' => $this->service->paginate($this->companyId($request), $request->only('search', 'expense_account_id', 'money_type', 'partner_id')),
            'filters'             => $request->only('search', 'expense_account_id', 'money_type', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/CashExpensePayments/Form', $this->formProps($request));
    }

    public function store(CashExpensePaymentRequest $request)
    {
        try {
            $payment = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.cash-expense-payments.index')->with('success', "Cash Expense Payment [{$payment->payment_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Always accessible, any status — posted/cancelled render read-only in the Vue form; only a draft is actually editable. */
    public function edit(Request $request, CashExpensePayment $cashExpensePayment)
    {
        return Inertia::render('Accounting/CashExpensePayments/Form', array_merge(
            $this->formProps($request),
            [
                'cashExpensePayment' => $cashExpensePayment->load([
                    'partner:id,code,name_en,name_ar',
                    'lines' => fn ($q) => $q->orderBy('sort_order'),
                    'lines.expenseAccount:id,code,name_en,requires_cost_center',
                    'lines.costCenter:id,code,name_en',
                    'currency:id,code', 'cheque', 'sourceBankAccount', 'sourceCreditFacility',
                    'allocations.project:id,name_en,contract_code,contract_value,partner_id',
                    'allocations.project.partner:id,name_en',
                    'allocations.cashExpensePaymentLine:id,cash_expense_payment_id,description,expense_account_id',
                    'allocations.cashExpensePaymentLine.expenseAccount:id,code,name_en',
                    'journalEntry.lines.chartOfAccount:id,code,name_en',
                ]),
                'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $cashExpensePayment->payment_date),
            ]
        ));
    }

    /** Only reachable when the payment is a draft (setToDraft() is the only way it gets there). */
    public function update(CashExpensePaymentRequest $request, CashExpensePayment $cashExpensePayment)
    {
        try {
            $payment = $this->service->update($cashExpensePayment, $request->validated(), $request->user());
            return redirect()->route('accounting.cash-expense-payments.index')->with('success', "Cash Expense Payment [{$payment->payment_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Reopens a posted payment back to draft so it can be edited — see CashExpensePaymentService::setToDraft(). */
    public function setToDraft(Request $request, CashExpensePayment $cashExpensePayment)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($cashExpensePayment, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($cashExpensePayment, $request->user()));
            return redirect()->route('accounting.cash-expense-payments.edit', $cashExpensePayment)->with('success', "Cash Expense Payment [{$cashExpensePayment->payment_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — the only way a payment becomes a draft is setToDraft(), which already reversed everything real. */
    public function destroy(Request $request, CashExpensePayment $cashExpensePayment)
    {
        try {
            $code = $cashExpensePayment->payment_code;
            $this->service->delete($cashExpensePayment, $request->user());
            return redirect()->route('accounting.cash-expense-payments.index')->with('success', "Cash Expense Payment [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, CashExpensePayment $cashExpensePayment)
    {
        try {
            $this->service->cancel($cashExpensePayment, $request->user());
            return back()->with('success', "Cash Expense Payment [{$cashExpensePayment->payment_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — searchable Partner list. No type restriction (partner is optional and generic here). */
    public function partners(Request $request)
    {
        $q = $request->string('q')->toString();

        $partners = Partner::active()
            ->when($q, fn ($query) => $query->search($q))
            ->orderBy('name_en')
            ->limit(50)
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->code} — {$p->name_en}"]);

        return response()->json(['results' => $partners])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — same combined Current Account + Overdraft picker as Other Payment / Supplier Payment. */
    public function sources(Request $request)
    {
        $companyId = $this->companyId($request);

        $bankAccounts = BankAccount::current()->active()->with('bank:id,name_en')
            ->orderBy('account_name')->get(['id', 'account_name', 'account_number', 'bank_id', 'currency_id'])
            ->map(fn ($a) => ['type' => 'bank_account', 'id' => $a->id, 'label' => "{$a->bank?->name_en} — {$a->account_name} ({$a->account_number})", 'currency_id' => $a->currency_id]);

        $facilities = CreditFacility::where('company_id', $companyId)
            ->payableSource()   // TRS-002 — now includes an undrawn Medium-Term Loan
            ->where('status', 'active')
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'facility_type', 'account_number'])
            ->map(fn ($f) => ['type' => 'credit_facility', 'id' => $f->id, 'label' => "{$f->bank?->name_en} — Overdraft ({$f->account_number})", 'currency_id' => null]);

        return response()->json(['sources' => $bankAccounts->concat($facilities)->values()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — this company's own 131xxx Safe sub-accounts. */
    public function safeAccounts(Request $request)
    {
        $companyId = $this->companyId($request);

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->orderBy('code')->get(['id', 'code', 'name_en']);

        return response()->json(['accounts' => $accounts])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — the company's default VAT rate from Tax Settings (suggested only, always editable — see CompanyTaxSetting). No such default exists for Withholding Tax anywhere in this system (Supplier Bill included) — always typed in fresh. */
    public function defaultVatRate(Request $request)
    {
        $companyId = $this->companyId($request);
        $rate = CompanyTaxSetting::where('company_id', $companyId)->value('vat_rate') ?? '0.00';

        return response()->json(['vat_rate' => (string) $rate])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — customers, for the "Allocating with Customer Contracts" section's Customer dropdown. Purely a filter to find the right Project; not itself stored. */
    public function customers(Request $request)
    {
        $customers = Partner::ofType('customer')->active()
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar']);

        return response()->json(['customers' => $customers])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — a customer's own Projects/Contracts, for the Contract Name dropdown once a Customer is picked. */
    public function projectsForCustomer(Request $request, int $partnerId)
    {
        $companyId = $this->companyId($request);

        $projects = \App\Models\Project::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->orderBy('contract_code')
            ->get(['id', 'name_en', 'contract_code', 'contract_value', 'currency_id'])
            ->load('currency:id,code');

        return response()->json(['projects' => $projects])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'costCenters'  => CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'expenseAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->postable()->ofClass('expenses')->orderBy('code')
                ->get(['id', 'code', 'name_en', 'requires_cost_center']),
        ];
    }
}
