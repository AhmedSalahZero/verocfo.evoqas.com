<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerInvoiceController
//
//  HTTP layer for Customer Invoices. Zero business logic — delegated
//  to CustomerInvoiceService. Mirrors SupplierBillController's shape.
//
//  eligibleSoLines() — live JSON endpoint the invoice form calls once
//  a Sales Order is picked — returns every line on that SO, each
//  annotated with how much of it has already been invoiced, so the
//  user can see what's left to bill (partial/milestone invoicing).
//
//  Routes (under auth + active.company):
//    GET    /accounting/customer-invoices                       → index()
//    GET    /accounting/customer-invoices/create                → create()
//    POST   /accounting/customer-invoices                       → store()
//    GET    /accounting/customer-invoices/{customerInvoice}/edit → edit()
//    PUT    /accounting/customer-invoices/{customerInvoice}      → update()
//    DELETE /accounting/customer-invoices/{customerInvoice}      → destroy()
//    PATCH  /accounting/customer-invoices/{customerInvoice}/post   → post()
//    PATCH  /accounting/customer-invoices/{customerInvoice}/cancel → cancel()
//    GET    /accounting/customer-invoices/eligible-so-lines      → eligibleSoLines() (JSON)
//
//  @layer    Controller
//  @depends  CustomerInvoiceService, CustomerInvoice, CustomerInvoiceRequest
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\SOValueExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CustomerInvoiceRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\CurrencyService;
use App\Services\FiscalYearService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerInvoiceController extends Controller
{
    public function __construct(
        private CustomerInvoiceService $service,
        private TaxService $taxService,
        private CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/CustomerInvoices/Index', [
            'customerInvoices' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id', 'sales_order_id')),
            'filters'          => $request->only('search', 'status', 'partner_id', 'sales_order_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/CustomerInvoices/Form', [
            'customerInvoice' => null,
            ...$this->formProps($request),
        ]);
    }

    public function store(CustomerInvoiceRequest $request)
    {
        try {
            $invoice = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.customer-invoices.index')->with('success', "Customer Invoice [{$invoice->invoice_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, CustomerInvoice $customerInvoice)
    {
        return Inertia::render('Accounting/CustomerInvoices/Form', [
            'customerInvoice' => $customerInvoice->load([
                'partner:id,name_en', 'salesOrder:id,so_code,so_value,invoiced_amount', 'currency:id,code',
                'lines.product:id,name_en', 'lines.uom:id,code', 'lines.revenueAccount:id,code,name_en',
                'lines.salesOrderLine:id,description,revenue_account_id',
                'allocations.customerPayment:id,payment_code,journal_entry_id',
                'journalEntry.lines.chartOfAccount:id,code,name_en',
            ]),
            'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $customerInvoice->invoice_date),
            ...$this->formProps($request),
        ]);
    }

    public function update(CustomerInvoiceRequest $request, CustomerInvoice $customerInvoice)
    {
        try {
            $this->service->update($this->companyId($request), $customerInvoice, $request->validated(), $request->user());
            return redirect()->route('accounting.customer-invoices.index')->with('success', 'Customer Invoice updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, CustomerInvoice $customerInvoice)
    {
        try {
            $this->service->delete($customerInvoice, $request->user());
            return redirect()->route('accounting.customer-invoices.index')->with('success', 'Draft Customer Invoice deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Reopens a posted invoice back to draft — see CustomerInvoiceService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, CustomerInvoice $customerInvoice)
    {
        try {
            // Audit H5: a posted tax document is no longer reopened on the spot —
            // this files a request (with its reason) for a DIFFERENT approver.
            app(\App\Services\Accounting\DocumentReopenService::class)->requestReopen($customerInvoice, $request->user(), (string) $request->input('reason'));
            return back()->with('success', "Customer Invoice [{$customerInvoice->invoice_code}] — reopen request sent for approval.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Confirmed with Mahmoud (August 2026): exceeding the SO value is
     * a warning, not always a hard block. First attempt (no override
     * param) — if it would exceed, SOValueExceededException is caught
     * separately here (it extends DomainException, so must be caught
     * BEFORE the generic catch below) and flashed under its own key so
     * the Vue index page can show a distinct "proceed anyway?" confirm
     * instead of a plain error banner. If the user confirms, the index
     * page resubmits with override_so_limit=true and this same action
     * posts it for real, flagged via exceeds_so_value.
     */
    public function post(Request $request, CustomerInvoice $customerInvoice)
    {
        try {
            $invoice = $this->service->post($customerInvoice, $request->user(), $request->boolean('override_so_limit'));
            $badge = $invoice->exceeds_so_value ? ' — note: this exceeded its Sales Order value.' : '';
            return back()->with('success', "Customer Invoice [{$invoice->invoice_code}] posted — Accounts Receivable created.{$badge}");
        } catch (SOValueExceededException $e) {
            return back()->with('so_value_exceeded', $e->getMessage());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, CustomerInvoice $customerInvoice)
    {
        try {
            $this->service->cancel($customerInvoice, $request->user());
            return back()->with('success', "Customer Invoice [{$customerInvoice->invoice_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * "Adjust Due Date" — used from the Invoices Report (Customer
     * Balances). Moves due_date only; original_due_date is untouched
     * forever — see CustomerInvoiceService::adjustDueDate().
     */
    public function adjustDueDate(Request $request, CustomerInvoice $customerInvoice)
    {
        $request->validate(['due_date' => ['required', 'date']]);

        try {
            $invoice = $this->service->adjustDueDate($customerInvoice, $request->input('due_date'), $request->user());
            return back()->with('success', "Due date for Invoice [{$invoice->invoice_code}] updated to {$invoice->due_date->toDateString()}.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — every line on a Sales Order, annotated with how much is already invoiced. */
    public function eligibleSoLines(Request $request)
    {
        $soId      = $request->integer('sales_order_id');
        $companyId = $this->companyId($request);

        $lines = SalesOrderLine::where('sales_order_id', $soId)
            ->with('product:id,name_en,name_ar,primary_uom_id,has_stock', 'revenueAccount:id,code,name_en')
            ->get()
            ->map(function ($line) use ($companyId) {
                // Fix (Delivery & Revenue Recognition): invoiced_quantity is
                // now a stored, authoritative running total maintained by
                // CustomerInvoiceService::post() itself (migration 000128) —
                // using that directly instead of a live re-sum keeps this
                // lookup consistent with what post() actually reads to
                // decide the accrued/deferred split, rather than a
                // second, independently-computed figure that could drift
                // from it (e.g. a live sum would include draft invoice
                // lines that haven't posted yet; the stored column
                // deliberately does not).
                return [
                    'id'                    => $line->id,
                    'description'           => $line->description,
                    'product_id'            => $line->product_id,
                    'product_name'          => $line->product?->name_en,
                    'has_stock'             => (bool) $line->product?->has_stock,
                    'quantity'              => (string) $line->quantity,
                    'delivered_quantity'    => (string) $line->delivered_quantity,
                    'invoiced_quantity'     => (string) $line->invoiced_quantity,
                    'net_position'          => $line->netDeliveryPosition(),
                    'unit_price'            => (string) $line->unit_price,
                    // VAT from Tax Settings (product → category → company default).
                    // A line with no product gets the company default rate.
                    'vat_rate'              => $line->product_id
                        ? $this->taxService->resolveVatRate($companyId, (int) $line->product_id)
                        : (string) $this->taxService->getOrCreateSettings($companyId)->vat_rate,
                    'uom_id'                => $line->uom_id ?? $line->product?->primary_uom_id,
                    'revenue_account_id'    => $line->revenue_account_id,
                    'revenue_account_label' => $line->revenueAccount ? "{$line->revenueAccount->code} — {$line->revenueAccount->name_en}" : null,
                ];
            });

        return response()->json(['lines' => $lines]);
    }

    /** Live lookup — Sales Orders eligible to be invoiced against (active, not yet fully invoiced) for a given customer. */
    public function eligibleSalesOrders(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id');

        $orders = SalesOrder::where('company_id', $companyId)
            ->where('status', 'active')
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->whereColumn('invoiced_amount', '<', 'so_value')
            ->orderByDesc('created_at')
            ->get(['id', 'so_code', 'partner_id', 'so_value', 'invoiced_amount', 'currency_id', 'exchange_rate']);

        return response()->json(['orders' => $orders]);
    }

    /**
     * Live lookup — this customer's OTHER posted collections that
     * still have cash available to match (unallocated_amount > 0) —
     * the "Other Unapplied Collections" picker on the invoice's own
     * page, confirmed by Mahmoud: an invoice should be able to pull a
     * collection onto itself, not just the other way around. Same
     * underlying allocate() action either direction — this is purely
     * a different starting point for the same match.
     */
    public function unappliedCollections(Request $request)
    {
        $partnerId = $request->integer('partner_id');

        $payments = \App\Models\CustomerPayment::unallocated()
            ->where('partner_id', $partnerId)
            ->orderByDesc('payment_date')
            ->get(['id', 'payment_code', 'payment_date', 'amount', 'unallocated_amount', 'receive_currency_id'])
            ->load('receiveCurrency:id,code');

        return response()->json(['payments' => $payments])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'customers'    => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'payment_term_id']),
            'currencies'   => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'revenueAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->where('account_type', 'Revenue')
                ->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
            'costCenters'  => \App\Models\CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
        ];
    }
}
