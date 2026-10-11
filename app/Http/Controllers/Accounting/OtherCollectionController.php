<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherCollectionController
//
//  HTTP layer for Other Collection. Zero business logic — delegated
//  to OtherCollectionService. See
//  VeroCFO_Other_Collection_Design_v1_0.md, the authoritative
//  reference.
//
//  Same lifecycle shape as CustomerPaymentController: record() posts
//  immediately (no draft stage on create); "Set to Draft" is the only
//  way to reopen a posted one for editing; delete() only ever
//  reachable on a draft; cancel() is the dead-end alternative to
//  reopening.
//
//  Routes (under auth + active.company):
//    GET    /accounting/other-collections                                → index()
//    GET    /accounting/other-collections/create                         → create()
//    POST   /accounting/other-collections                                → store()
//    GET    /accounting/other-collections/{otherCollection}/edit         → edit()
//    PUT    /accounting/other-collections/{otherCollection}              → update()
//    DELETE /accounting/other-collections/{otherCollection}              → destroy()
//    PATCH  /accounting/other-collections/{otherCollection}/set-to-draft → setToDraft()
//    PATCH  /accounting/other-collections/{otherCollection}/cancel       → cancel()
//    GET    /accounting/other-collections/partners                      → partners() (JSON)
//    GET    /accounting/other-collections/default-credit-account        → defaultCreditAccount() (JSON)
//    GET    /accounting/other-collections/destinations                  → destinations() (JSON)
//    GET    /accounting/other-collections/safe-accounts                 → safeAccounts() (JSON)
//    GET    /accounting/other-collections/credit-accounts               → creditAccounts() (JSON)
//
//  @layer    Controller
//  @depends  OtherCollectionService, OtherCollection, OtherCollectionRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\OtherCollectionRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\OtherCollection;
use App\Models\Partner;
use App\Services\Accounting\OtherCollectionService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OtherCollectionController extends Controller
{
    public function __construct(
        private OtherCollectionService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/OtherCollections/Index', [
            'otherCollections' => $this->service->paginate($this->companyId($request), $request->only('search', 'partner_type', 'money_type', 'partner_id')),
            'filters'          => $request->only('search', 'partner_type', 'money_type', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/OtherCollections/Form', $this->formProps($request));
    }

    public function store(OtherCollectionRequest $request)
    {
        try {
            $collection = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.other-collections.index')->with('success', "Other Collection [{$collection->collection_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Always accessible, any status — posted/cancelled render read-only in the Vue form; only a draft is actually editable. */
    public function edit(Request $request, OtherCollection $otherCollection)
    {
        return Inertia::render('Accounting/OtherCollections/Form', array_merge(
            $this->formProps($request),
            [
                'otherCollection' => $otherCollection->load([
                    'partner:id,code,name_en,name_ar,types', 'currency:id,code', 'creditAccount:id,code,name_en',
                    'cheque', 'destinationBankAccount', 'destinationCreditFacility', 'journalEntry.lines.chartOfAccount:id,code,name_en',
                ]),
                'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $otherCollection->collection_date),
            ]
        ));
    }

    /** Only reachable when the collection is a draft (setToDraft() is the only way it gets there). */
    public function update(OtherCollectionRequest $request, OtherCollection $otherCollection)
    {
        try {
            $collection = $this->service->update($otherCollection, $request->validated(), $request->user());
            return redirect()->route('accounting.other-collections.index')->with('success', "Other Collection [{$collection->collection_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Reopens a posted collection back to draft so it can be edited — see OtherCollectionService::setToDraft(). */
    public function setToDraft(Request $request, OtherCollection $otherCollection)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($otherCollection, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($otherCollection, $request->user()));
            return redirect()->route('accounting.other-collections.edit', $otherCollection)->with('success', "Other Collection [{$otherCollection->collection_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — the only way a collection becomes a draft is setToDraft(), which already reversed everything real. */
    public function destroy(Request $request, OtherCollection $otherCollection)
    {
        try {
            $code = $otherCollection->collection_code;
            $this->service->delete($otherCollection, $request->user());
            return redirect()->route('accounting.other-collections.index')->with('success', "Other Collection [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, OtherCollection $otherCollection)
    {
        try {
            $this->service->cancel($otherCollection, $request->user());
            return back()->with('success', "Other Collection [{$otherCollection->collection_code}] cancelled.");
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

    /** Live lookup — the suggested default credit account for a Partner Type (user can always override). Employee is further routed by employee_advance_type — see OtherCollection::defaultCreditAccountCode(). Never suggests a non-postable header account — see the Building Bible note on 139/140/230 being parent-level, no-postable-sub-account codes. */
    public function defaultCreditAccount(Request $request)
    {
        $companyId = $this->companyId($request);
        $type = $request->string('partner_type')->toString();
        $employeeAdvanceType = $request->string('employee_advance_type')->toString() ?: null;

        $code = OtherCollection::defaultCreditAccountCode($type, $employeeAdvanceType);
        $account = $code ? ChartOfAccount::findByCode($companyId, $code) : null;

        // Some default codes (139, 140, 230) are non-postable parent/header
        // accounts with no postable sub-account seeded under them yet —
        // suggesting them would hand back an account the journal entry can
        // never actually post to. Silently skip the suggestion rather than
        // offering something broken; the user picks manually instead.
        if ($account && ! $account->is_postable) {
            $account = null;
        }

        return response()->json(['account' => $account])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — same combined Current Account + Overdraft picker as Customer Collection. */
    public function destinations(Request $request)
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

        return response()->json(['destinations' => $bankAccounts->concat($facilities)->values()])
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
     * Credit Account picker (SearchableSelect component contract:
     * { results: [{ id, label }] }).
     *
     * Restricted to postable BALANCE SHEET accounts only (Assets,
     * Liabilities, Equity) — never Income Statement (Revenue/Expense)
     * accounts, which could never be the correct answer for a
     * collection's credit side. Confirmed with Mahmoud (August 2026):
     * rather than guess a narrower "correct" subset ourselves (which
     * produced the original bug — suggesting non-postable header
     * accounts, and burying real candidates under Fixed Assets/OD
     * sub-accounts in an unfiltered list), show every genuine
     * candidate and let the accountant choose.
     */
    public function creditAccounts(Request $request)
    {
        $companyId = $this->companyId($request);
        $q = $request->string('q')->toString();

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->postable()
            ->ofSection('balance_sheet')
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
            'partnerTypes' => OtherCollection::ALL_PARTNER_TYPES,
            'employeeAdvanceTypes' => collect(OtherCollection::ALL_EMPLOYEE_ADVANCE_TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => OtherCollection::employeeAdvanceTypeLabel($t)])
                ->values(),
            'banks'        => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}
