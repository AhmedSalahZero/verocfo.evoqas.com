<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerPaymentController
//
//  HTTP layer for Customer Collection. Zero business logic —
//  delegated to CustomerPaymentService. See
//  VeroCFO_Customer_Collection_Design_v1_0.md, the authoritative
//  reference.
//
//  No edit/update/destroy — record() posts immediately (design doc:
//  no draft stage, matches the single-Save screenshots this module
//  was built from). cancel() is the only way to undo one, and only
//  before anything has been allocated (see CustomerPaymentService::
//  cancel()).
//
//  allocate()/unallocate() are JSON endpoints — same pattern as
//  ContractAssignmentOdController's addAssignment()/removeAssignment().
//  This is also the "Apply Down Payment" action from the Index page.
//
//  Routes (under auth + active.company):
//    GET    /accounting/customer-payments                          → index()
//    GET    /accounting/customer-payments/create                    → create()
//    POST   /accounting/customer-payments                           → store()
//    PATCH  /accounting/customer-payments/{customerPayment}/cancel  → cancel()
//    POST   /accounting/customer-payments/{customerPayment}/allocate    → allocate() (JSON)
//    DELETE /accounting/customer-payment-allocations/{allocation}       → unallocate() (JSON)
//    GET    /accounting/customer-payments/open-invoices              → openInvoices() (JSON)
//    GET    /accounting/customer-payments/destinations               → destinations() (JSON)
//
//  @layer    Controller
//  @depends  CustomerPaymentService, CustomerPayment, CustomerPaymentRequest, AllocatePaymentRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AllocatePaymentRequest;
use App\Http\Requests\Accounting\CustomerPaymentRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Partner;
use App\Models\Project;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private CustomerPaymentService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/CustomerPayments/Index', [
            'customerPayments' => $this->service->paginate($this->companyId($request), $request->only('search', 'collection_type', 'money_type', 'partner_id')),
            'filters'          => $request->only('search', 'collection_type', 'money_type', 'partner_id'),
        ]);
    }

    /**
     * Optional ?invoice_id= — arrives from the "Collect" button on the
     * Customer Invoice list (CustomerInvoices/Index.vue). Only that one
     * invoice is passed down as preselectedInvoice; the Vue form uses
     * it to pre-fill Customer + Invoice Currency and to show just that
     * single row in Settlement Information, instead of every open
     * invoice for the customer (the normal "New Customer Collection"
     * behaviour, unchanged). Silently ignored if the invoice doesn't
     * belong to this company, isn't posted, or has nothing outstanding
     * — the form then simply opens exactly as it does today.
     */
    public function create(Request $request)
    {
        $preselectedInvoice = null;

        if ($invoiceId = $request->integer('invoice_id')) {
            $invoice = CustomerInvoice::where('id', $invoiceId)
                ->where('status', 'posted')
                ->where('amount_outstanding', '>', 0)
                ->first(['id', 'invoice_code', 'partner_id', 'currency_id', 'invoice_date', 'invoice_value', 'amount_paid', 'amount_outstanding']);

            if ($invoice) {
                $preselectedInvoice = $invoice;
            }
        }

        return Inertia::render('Accounting/CustomerPayments/Form', array_merge(
            $this->formProps($request),
            ['preselectedInvoice' => $preselectedInvoice]
        ));
    }

    public function store(CustomerPaymentRequest $request)
    {
        try {
            $payment = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            $label = $payment->isDownPayment() ? 'Down Payment' : 'Collection';
            return redirect()->route('accounting.customer-payments.index')->with('success', "Customer {$label} [{$payment->payment_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Always accessible, any status — matches every other module going
     * forward (confirmed with Mahmoud: an ERP must let a user open and
     * see any transaction, not just drafts). Posted/cancelled render
     * read-only in the Vue form; only a draft is actually editable.
     */
    public function edit(Request $request, CustomerPayment $customerPayment)
    {
        return Inertia::render('Accounting/CustomerPayments/Form', array_merge(
            $this->formProps($request),
            [
                'customerPayment' => $customerPayment->load([
                    'partner:id,code,name_en,name_ar', 'receiveCurrency:id,code', 'invoiceCurrency:id,code',
                    'cheque', 'allocations.customerInvoice:id,invoice_code',
                    'destinationBankAccount', 'destinationCreditFacility', 'journalEntry.lines.chartOfAccount:id,code,name_en',
                ]),
                'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $customerPayment->payment_date),
            ]
        ));
    }

    /** Only reachable when the collection is a draft (setToDraft() is the only way it gets there) — see CustomerPaymentService::update(). */
    public function update(CustomerPaymentRequest $request, CustomerPayment $customerPayment)
    {
        try {
            $payment = $this->service->update($this->companyId($request), $customerPayment, $request->validated(), $request->user());
            return redirect()->route('accounting.customer-payments.index')->with('success', "Customer Collection [{$payment->payment_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Reopens a posted collection back to draft so it can be edited — see CustomerPaymentService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, CustomerPayment $customerPayment)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($customerPayment, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($customerPayment, $request->user()));
            return redirect()->route('accounting.customer-payments.edit', $customerPayment)->with('success', "Customer Collection [{$customerPayment->payment_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — the only way a collection becomes a draft is setToDraft(), which already reversed everything real. See CustomerPaymentService::delete(). */
    public function destroy(Request $request, CustomerPayment $customerPayment)
    {
        try {
            $code = $customerPayment->payment_code;
            $this->service->delete($customerPayment, $request->user());
            return redirect()->route('accounting.customer-payments.index')->with('success', "Customer Collection [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, CustomerPayment $customerPayment)
    {
        try {
            $this->service->cancel($customerPayment, $request->user());
            return back()->with('success', "Customer Collection [{$customerPayment->payment_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function allocate(AllocatePaymentRequest $request, CustomerPayment $customerPayment)
    {
        try {
            $invoice = CustomerInvoice::findOrFail($request->validated('customer_invoice_id'));
            $allocation = $this->service->allocate($customerPayment, $invoice, (string) $request->validated('amount'), $request->user(), $request->validated('application_date'));
            return response()->json(['allocation' => $allocation, 'message' => 'Allocated successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unallocate(Request $request, CustomerPaymentAllocation $allocation)
    {
        try {
            $this->service->unallocate($allocation, $request->user());
            return response()->json(['message' => 'Unallocated successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Live lookup — posted, still-open invoices for a customer, filtered
     * by Invoice Currency (the multi-select + Apply Down Payment picker).
     *
     * Optional ?invoice_id= narrows the result down to that one invoice
     * only — used when the form was opened via the "Collect" button on
     * a specific invoice (see create() above), so Settlement Information
     * shows just that row instead of every open invoice for the customer.
     */
    public function openInvoices(Request $request)
    {
        $partnerId = $request->integer('partner_id');
        $currencyId = $request->integer('invoice_currency_id') ?: null;
        $invoiceId = $request->integer('invoice_id') ?: null;

        $invoices = CustomerInvoice::open()
            ->where('partner_id', $partnerId)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->when($invoiceId, fn ($q) => $q->where('id', $invoiceId))
            ->with('salesOrder:id,so_code')
            ->orderByDesc('invoice_date')
            ->get(['id', 'invoice_code', 'sales_order_id', 'invoice_date', 'currency_id', 'exchange_rate', 'invoice_value', 'amount_paid', 'amount_outstanding']);

        // FX-001 fix (8 Sep 2026) — amount_outstanding is always in
        // functional currency (see CustomerBalanceService header), but
        // Settlement Amount is entered in the invoice's OWN currency.
        // Without this, the screen showed the functional figure right
        // next to a box that expected the native one — same amount,
        // two different currencies, no visual distinction. This adds
        // the native-currency equivalent using the exact conversion
        // CustomerBalanceService::nativeAmount() already documents and
        // uses: functional ÷ the invoice's own exchange_rate.
        $invoices->each(function ($invoice) {
            $rate = bccomp((string) $invoice->exchange_rate, '0', 6) > 0 ? (string) $invoice->exchange_rate : '1';
            $invoice->amount_outstanding_native = bcdiv((string) $invoice->amount_outstanding, $rate, 2);
        });

        // Fix (August 2026, real bug found via Mahmoud's report): without
        // this header, a browser can silently serve a stale cached
        // response for this exact GET URL instead of re-asking the
        // server — meaning "Set to Draft" could correctly reverse an
        // invoice's Collected/Net Balance in the database, while this
        // screen kept showing the OLD figures from before the reversal.
        // The frontend fetch() call now also sends cache: 'no-store' —
        // this header is the server-side half of the same fix, in case
        // anything else (a proxy, a different caller) sits in between.
        return response()->json(['invoices' => $invoices])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Live lookup — every valid deposit/collection destination: this
     * company's active Current accounts AND active Overdraft
     * facilities, tagged so the frontend can build one combined
     * picker (design doc Section 4 — confirmed by Mahmoud, either is
     * a valid destination for Bank Deposit, Incoming Transfer, and
     * a cheque's "sent for collection" destination).
     */
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

    /** Live lookup — this company's own 131xxx Safe sub-accounts, for Cash in Safe / a cheque's Receiving Safe tag. */
    public function safeAccounts(Request $request)
    {
        $companyId = $this->companyId($request);

        $accounts = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->orderBy('code')->get(['id', 'code', 'name_en']);

        return response()->json(['accounts' => $accounts])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'customers'  => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'banks'      => Bank::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'projects'   => Project::active()->orderBy('name_en')->get(['id', 'name_en', 'contract_code']),
            'currencies' => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}
