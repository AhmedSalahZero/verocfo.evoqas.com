<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierPaymentController
//
//  HTTP layer for Supplier Payment. Zero business logic — delegated
//  to SupplierPaymentService. Payable-side mirror of
//  CustomerPaymentController, including the Reopen-to-Draft pattern
//  (edit always accessible, Set to Draft/Delete/Cancel) already
//  proven on the customer side.
//
//  Routes (under auth + active.company):
//    GET    /accounting/supplier-payments                              → index()
//    GET    /accounting/supplier-payments/create                       → create()
//    POST   /accounting/supplier-payments                              → store()
//    GET    /accounting/supplier-payments/{supplierPayment}/edit       → edit()
//    PUT    /accounting/supplier-payments/{supplierPayment}            → update()
//    DELETE /accounting/supplier-payments/{supplierPayment}            → destroy()
//    PATCH  /accounting/supplier-payments/{supplierPayment}/set-to-draft → setToDraft()
//    PATCH  /accounting/supplier-payments/{supplierPayment}/cancel     → cancel()
//    POST   /accounting/supplier-payments/{supplierPayment}/allocate   → allocate() (JSON)
//    DELETE /accounting/supplier-payment-allocations/{allocation}      → unallocate() (JSON)
//    GET    /accounting/supplier-payments/open-bills                   → openBills() (JSON)
//    GET    /accounting/supplier-payments/sources                      → sources() (JSON)
//    GET    /accounting/supplier-payments/safe-accounts                → safeAccounts() (JSON)
//
//  @layer    Controller
//  @depends  SupplierPaymentService, SupplierPayment, SupplierPaymentRequest, AllocateSupplierPaymentRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AllocateSupplierPaymentRequest;
use App\Http\Requests\Accounting\SupplierPaymentRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Partner;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private SupplierPaymentService $service,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/SupplierPayments/Index', [
            'supplierPayments' => $this->service->paginate($this->companyId($request), $request->only('search', 'payment_type', 'money_type', 'partner_id')),
            'filters'          => $request->only('search', 'payment_type', 'money_type', 'partner_id'),
        ]);
    }

    /**
     * Optional ?bill_id= — arrives from the "Pay" button on the Supplier
     * Bill list (SupplierBills/Index.vue). Same pattern as
     * CustomerPaymentController::create()'s ?invoice_id= — only that
     * one bill is passed down as preselectedBill; the Vue form uses it
     * to pre-fill Supplier + Bill Currency and to show just that single
     * row in Settlement Information, instead of every open bill for the
     * supplier (the normal "New Supplier Payment" behaviour, unchanged).
     * Silently ignored if the bill doesn't belong to this company,
     * isn't posted, or has nothing outstanding — the form then simply
     * opens exactly as it does today.
     */
    public function create(Request $request)
    {
        $preselectedBill = null;

        if ($billId = $request->integer('bill_id')) {
            $bill = SupplierBill::where('id', $billId)
                ->where('status', 'posted')
                ->where('amount_outstanding', '>', 0)
                ->first(['id', 'bill_code', 'partner_id', 'currency_id', 'bill_date', 'bill_value', 'amount_paid', 'amount_outstanding']);

            if ($bill) {
                $preselectedBill = $bill;
            }
        }

        return Inertia::render('Accounting/SupplierPayments/Form', array_merge(
            $this->formProps($request),
            ['preselectedBill' => $preselectedBill]
        ));
    }

    public function store(SupplierPaymentRequest $request)
    {
        try {
            $payment = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            $label = $payment->isDownPayment() ? 'Down Payment' : 'Payment';
            return redirect()->route('accounting.supplier-payments.index')->with('success', "Supplier {$label} [{$payment->payment_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Always accessible, any status — same reasoning as Customer Collection. Posted/cancelled render read-only; only a draft is editable. */
    public function edit(Request $request, SupplierPayment $supplierPayment)
    {
        return Inertia::render('Accounting/SupplierPayments/Form', array_merge(
            $this->formProps($request),
            [
                'supplierPayment' => $supplierPayment->load([
                    'partner:id,code,name_en,name_ar', 'payCurrency:id,code', 'billCurrency:id,code',
                    'cheque', 'allocations.supplierBill:id,bill_code',
                    'sourceBankAccount', 'sourceCreditFacility', 'journalEntry.lines.chartOfAccount:id,code,name_en',
                ]),
                'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $supplierPayment->payment_date),
            ]
        ));
    }

    /** Only reachable when the payment is a draft (setToDraft() is the only way it gets there) — see SupplierPaymentService::update(). */
    public function update(SupplierPaymentRequest $request, SupplierPayment $supplierPayment)
    {
        try {
            $payment = $this->service->update($this->companyId($request), $supplierPayment, $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-payments.index')->with('success', "Supplier Payment [{$payment->payment_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Reopens a posted payment back to draft — see SupplierPaymentService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, SupplierPayment $supplierPayment)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($supplierPayment, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($supplierPayment, $request->user()));
            return redirect()->route('accounting.supplier-payments.edit', $supplierPayment)->with('success', "Supplier Payment [{$supplierPayment->payment_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — see SupplierPaymentService::delete(). */
    public function destroy(Request $request, SupplierPayment $supplierPayment)
    {
        try {
            $code = $supplierPayment->payment_code;
            $this->service->delete($supplierPayment, $request->user());
            return redirect()->route('accounting.supplier-payments.index')->with('success', "Supplier Payment [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierPayment $supplierPayment)
    {
        try {
            $this->service->cancel($supplierPayment, $request->user());
            return back()->with('success', "Supplier Payment [{$supplierPayment->payment_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function allocate(AllocateSupplierPaymentRequest $request, SupplierPayment $supplierPayment)
    {
        try {
            $bill = SupplierBill::findOrFail($request->validated('supplier_bill_id'));
            $allocation = $this->service->allocate($supplierPayment, $bill, (string) $request->validated('amount'), $request->user(), $request->validated('application_date'));
            return response()->json(['allocation' => $allocation, 'message' => 'Allocated successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unallocate(Request $request, SupplierPaymentAllocation $allocation)
    {
        try {
            $this->service->unallocate($allocation, $request->user());
            return response()->json(['message' => 'Unallocated successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Live lookup — posted, still-open bills for a supplier, filtered
     * by Bill Currency.
     *
     * Optional ?bill_id= narrows the result down to that one bill only
     * — used when the form was opened via the "Pay" button on a
     * specific bill (see create() above), so Settlement Information
     * shows just that row instead of every open bill for the supplier.
     */
    public function openBills(Request $request)
    {
        $partnerId = $request->integer('partner_id');
        $currencyId = $request->integer('bill_currency_id') ?: null;
        $billId = $request->integer('bill_id') ?: null;

        $bills = SupplierBill::open()
            ->where('partner_id', $partnerId)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->when($billId, fn ($q) => $q->where('id', $billId))
            ->with('purchaseOrder:id,po_code')
            ->orderByDesc('bill_date')
            ->get(['id', 'bill_code', 'purchase_order_id', 'bill_date', 'currency_id', 'exchange_rate', 'bill_value', 'amount_paid', 'amount_outstanding']);

        // FX-001 fix (8 Sep 2026) — same fix as Customer Collection's
        // open-invoices endpoint: amount_outstanding is functional
        // currency, Settlement Amount is entered in the bill's own
        // currency. Add the native-currency equivalent so the screen
        // and the input box always agree on which currency they mean.
        $bills->each(function ($bill) {
            $rate = bccomp((string) $bill->exchange_rate, '0', 6) > 0 ? (string) $bill->exchange_rate : '1';
            $bill->amount_outstanding_native = bcdiv((string) $bill->amount_outstanding, $rate, 2);
        });

        // Same fix as Customer Collection's open-invoices endpoint —
        // never let a browser silently serve a stale cached response
        // for this URL after a Set to Draft reversal.
        return response()->json(['bills' => $bills])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — every valid payment source: active Current accounts AND active Overdraft facilities. */
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

    /** Live lookup — this company's own 131xxx Safe sub-accounts, for Cash Payment. */
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
            'suppliers'  => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'   => Project::active()->orderBy('name_en')->get(['id', 'name_en', 'contract_code']),
            'purchaseOrders' => PurchaseOrder::active()->orderBy('po_code')->get(['id', 'po_code', 'partner_id', 'subject']),
            'currencies' => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
        ];
    }
}
