<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherPaymentController
//
//  HTTP layer for Other Payment. Zero business logic — delegated to
//  OtherPaymentService. See VeroCFO_Other_Payment_Design_v1_0.md.
//
//  Same lifecycle shape as SupplierPaymentController: record() posts
//  immediately (no draft stage on create); "Set to Draft" is the only
//  way to reopen a posted one for editing; delete() only ever
//  reachable on a draft; cancel() is the dead-end alternative.
//
//  Routes (under auth + active.company):
//    GET    /accounting/other-payments                                → index()
//    GET    /accounting/other-payments/create                         → create()
//    POST   /accounting/other-payments                                → store()
//    GET    /accounting/other-payments/{otherPayment}/edit            → edit()
//    PUT    /accounting/other-payments/{otherPayment}                 → update()
//    DELETE /accounting/other-payments/{otherPayment}                 → destroy()
//    PATCH  /accounting/other-payments/{otherPayment}/set-to-draft    → setToDraft()
//    PATCH  /accounting/other-payments/{otherPayment}/cancel          → cancel()
//    GET    /accounting/other-payments/partners                       → partners() (JSON)
//    GET    /accounting/other-payments/default-debit-account          → defaultDebitAccount() (JSON)
//    GET    /accounting/other-payments/debit-accounts                 → debitAccounts() (JSON)
//    GET    /accounting/other-payments/sources                        → sources() (JSON)
//    GET    /accounting/other-payments/safe-accounts                  → safeAccounts() (JSON)
//
//  @layer    Controller
//  @depends  OtherPaymentService, OtherPayment, OtherPaymentRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\OtherPaymentRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\OtherPayment;
use App\Models\Partner;
use App\Services\Accounting\OtherPaymentService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OtherPaymentController extends Controller
{
    public function __construct(
        private OtherPaymentService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/OtherPayments/Index', [
            'otherPayments' => $this->service->paginate($this->companyId($request), $request->only('search', 'partner_type', 'money_type', 'partner_id')),
            'filters'       => $request->only('search', 'partner_type', 'money_type', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);
        $prefillAccountId = $request->integer('debit_account_id') ?: null;
        $prefillAccount = $prefillAccountId ? ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->find($prefillAccountId) : null;

        return Inertia::render('Accounting/OtherPayments/Form', array_merge(
            $this->formProps($request),
            [
                'prefill' => array_merge(
                    $request->only('partner_type', 'partner_id', 'employee_advance_type'),
                    ['debit_account_id' => $prefillAccountId]
                ),
                // So the SearchableSelect shows "226005 — Salaries Tax
                // Payable" immediately rather than a blank box the user
                // has to search to fill in themselves — see Government
                // Remittance's "Remit Tax"/"Remit Social Insurance" links.
                'prefillDebitAccountLabel' => $prefillAccount ? "{$prefillAccount->code} — {$prefillAccount->name_en}" : null,
            ]
        ));
    }

    public function store(OtherPaymentRequest $request)
    {
        try {
            $payment = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.other-payments.index')->with('success', "Other Payment [{$payment->payment_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Always accessible, any status — posted/cancelled render read-only in the Vue form; only a draft is actually editable. */
    public function edit(Request $request, OtherPayment $otherPayment)
    {
        return Inertia::render('Accounting/OtherPayments/Form', array_merge(
            $this->formProps($request),
            [
                'otherPayment' => $otherPayment->load([
                    'partner:id,code,name_en,name_ar,types', 'currency:id,code', 'debitAccount:id,code,name_en',
                    'cheque', 'sourceBankAccount', 'sourceCreditFacility', 'journalEntry.lines.chartOfAccount:id,code,name_en',
                ]),
                'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $otherPayment->payment_date),
            ]
        ));
    }

    /** Only reachable when the payment is a draft (setToDraft() is the only way it gets there). */
    public function update(OtherPaymentRequest $request, OtherPayment $otherPayment)
    {
        try {
            $payment = $this->service->update($otherPayment, $request->validated(), $request->user());
            return redirect()->route('accounting.other-payments.index')->with('success', "Other Payment [{$payment->payment_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Reopens a posted payment back to draft so it can be edited — see OtherPaymentService::setToDraft(). */
    public function setToDraft(Request $request, OtherPayment $otherPayment)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($otherPayment, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($otherPayment, $request->user()));
            return redirect()->route('accounting.other-payments.edit', $otherPayment)->with('success', "Other Payment [{$otherPayment->payment_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — the only way a payment becomes a draft is setToDraft(), which already reversed everything real. */
    public function destroy(Request $request, OtherPayment $otherPayment)
    {
        try {
            $code = $otherPayment->payment_code;
            $this->service->delete($otherPayment, $request->user());
            return redirect()->route('accounting.other-payments.index')->with('success', "Other Payment [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, OtherPayment $otherPayment)
    {
        try {
            $this->service->cancel($otherPayment, $request->user());
            return back()->with('success', "Other Payment [{$otherPayment->payment_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — partners filtered by the Partner Type selected in the form. */
    public function partners(Request $request)
    {
        $type = $request->string('partner_type')->toString();

        $partners = Partner::when($type, fn ($q) => $q->ofType($type))
            ->active()->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar', 'types']);

        return response()->json(['partners' => $partners])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — the suggested default debit account for a Partner Type (user can always override). Employee is further routed by employee_advance_type — see OtherPayment::defaultDebitAccountCode(). Never suggests a non-postable header account — same fix as OtherCollectionController::defaultCreditAccount(). */
    public function defaultDebitAccount(Request $request)
    {
        $companyId = $this->companyId($request);
        $type = $request->string('partner_type')->toString();
        $employeeAdvanceType = $request->string('employee_advance_type')->toString() ?: null;

        $code = OtherPayment::defaultDebitAccountCode($type, $employeeAdvanceType);
        $account = $code ? ChartOfAccount::findByCode($companyId, $code) : null;

        // Same fix as Other Collection — 139, 140, and 230 are non-postable
        // parent/header accounts with no postable sub-account seeded under
        // them yet. Skip the suggestion rather than offering a broken one.
        if ($account && ! $account->is_postable) {
            $account = null;
        }

        return response()->json(['account' => $account])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — same combined Current Account + Overdraft picker as Supplier Payment. */
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

    /**
     * Live lookup — searchable Chart of Accounts list for the manual
     * Debit Account picker. Same Balance-Sheet-only restriction and
     * same reasoning as OtherCollectionController::creditAccounts() —
     * see that method's doc block.
     *
     * ?section=income_statement / ?class=expenses (2026-09, Advances &
     * Settlements / Employee Expense Reimbursements) — Per Diem and
     * Reimbursement are outright EXPENSES, not receivables, so the
     * frontend requests expense accounts instead. BUG FIX: filtering
     * by section=income_statement alone also returns Revenue accounts
     * (income_statement covers both) — class=expenses is the correct,
     * narrower filter and is applied whenever the frontend sends a
     * ?class param, taking priority over ?section. Defaults to
     * section=balance_sheet so every other partner type/caller is
     * unaffected.
     */
    public function debitAccounts(Request $request)
    {
        $companyId = $this->companyId($request);
        $q = $request->string('q')->toString();
        $class = $request->string('class')->toString();
        $section = $request->string('section')->toString() ?: 'balance_sheet';

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->postable()
            ->when($class, fn ($query) => $query->ofClass($class), fn ($query) => $query->ofSection($section))
            ->when($q, fn ($query) => $query->where(fn ($qq) => $qq->where('code', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%")->orWhere('name_ar', 'like', "%{$q}%")))
            ->orderBy('code')
            ->limit(50)
            ->get(['id', 'code', 'name_en'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name_en}"]);

        return response()->json(['results' => $accounts]);
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'partnerTypes' => OtherPayment::ALL_PARTNER_TYPES,
            'employeeAdvanceTypes' => collect(OtherPayment::ALL_EMPLOYEE_ADVANCE_TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => OtherPayment::employeeAdvanceTypeLabel($t)])
                ->values(),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}