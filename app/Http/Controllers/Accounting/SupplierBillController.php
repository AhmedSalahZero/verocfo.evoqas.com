<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierBillController
//
//  HTTP layer for Supplier Bills. Zero business logic — delegated to
//  SupplierBillService. Mirrors GoodsReceiptNoteController's pattern.
//
//  eligibleGrnLines() — live JSON endpoint the Goods Bill form calls
//  once a supplier (and optionally a PO) is picked — returns only
//  posted GRN lines for that supplier still carrying a remaining
//  billable quantity > 0 (GoodsReceiptNoteLine::remainingBillableQuantity()).
//
//  Routes (under auth + active.company):
//    GET    /accounting/supplier-bills                       → index()
//    GET    /accounting/supplier-bills/create                → create()
//    POST   /accounting/supplier-bills                       → store()
//    GET    /accounting/supplier-bills/{supplierBill}/edit    → edit()
//    PUT    /accounting/supplier-bills/{supplierBill}         → update()
//    DELETE /accounting/supplier-bills/{supplierBill}         → destroy()
//    PATCH  /accounting/supplier-bills/{supplierBill}/post    → post()
//    PATCH  /accounting/supplier-bills/{supplierBill}/cancel  → cancel()
//    PATCH  /accounting/supplier-bills/{supplierBill}/set-to-draft → setToDraft()
//    GET    /accounting/supplier-bills/eligible-grn-lines     → eligibleGrnLines() (JSON)
//    GET    /accounting/supplier-bills/unapplied-payments     → unappliedPayments() (JSON)
//
//  @layer    Controller
//  @depends  SupplierBillService, SupplierBill, SupplierBillRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SupplierBillRequest;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\Accounting\SupplierBillService;
use App\Services\FiscalYearService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierBillController extends Controller
{
    public function __construct(
        private SupplierBillService $service,
        private TaxService $taxService,
        private \App\Services\CurrencyService $currencyService,
        private FiscalYearService $fiscalYearService
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/SupplierBills/Index', [
            'supplierBills' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'bill_type', 'partner_id')),
            'filters'       => $request->only('search', 'status', 'bill_type', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/SupplierBills/Form', [
            'supplierBill' => null,
            ...$this->formProps($request),
        ]);
    }

    public function store(SupplierBillRequest $request)
    {
        try {
            $bill = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-bills.index')->with('success', "Supplier Bill [{$bill->bill_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, SupplierBill $supplierBill)
    {
        return Inertia::render('Accounting/SupplierBills/Form', [
            'supplierBill' => $supplierBill->load([
                'partner:id,name_en', 'purchaseOrder:id,po_code,partner_id', 'currency:id,code',
                'lines.product:id,name_en', 'lines.uom:id,code', 'lines.chartOfAccount:id,code,name_en', 'lines.accrual:id,accrual_code',
                // Fix (July 2026): without this, "GRN Cost" showed 0
                // in Edit mode — that column was only ever filled in
                // by a live @change on the dropdown, which never fires
                // for a line that's already selected on page load.
                'lines.goodsReceiptNoteLine:id,unit_cost,quantity,billed_quantity,product_id,goods_receipt_note_id,uom_id',
                'lines.goodsReceiptNoteLine.goodsReceiptNote:id,grn_code',
                'lines.goodsReceiptNoteLine.product:id,name_en',
                'lines.amortizationEntries' => fn ($q) => $q->orderBy('period_number'), // Prepaid Expense Bill — schedule panel
                'allocations.supplierPayment:id,payment_code,journal_entry_id',
            ]),
            'periodClosed' => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $supplierBill->bill_date),
            ...$this->formProps($request),
        ]);
    }

    public function update(SupplierBillRequest $request, SupplierBill $supplierBill)
    {
        try {
            $this->service->update($this->companyId($request), $supplierBill, $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-bills.index')->with('success', 'Supplier Bill updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, SupplierBill $supplierBill)
    {
        $this->service->delete($supplierBill, $request->user());
        return redirect()->route('accounting.supplier-bills.index')->with('success', 'Draft Supplier Bill deleted.');
    }

    public function post(Request $request, SupplierBill $supplierBill)
    {
        try {
            $bill = $this->service->post($supplierBill, $request->user());
            return back()->with('success', "Supplier Bill [{$bill->bill_code}] posted — Accounts Payable created.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierBill $supplierBill)
    {
        try {
            $this->service->cancel($supplierBill, $request->user());
            return back()->with('success', "Supplier Bill [{$supplierBill->bill_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * "Adjust Due Date" — used from the Invoices Report (Supplier
     * Balances). Moves due_date only; original_due_date is untouched
     * forever — see SupplierBillService::adjustDueDate().
     */
    public function adjustDueDate(Request $request, SupplierBill $supplierBill)
    {
        $request->validate(['due_date' => ['required', 'date']]);

        try {
            $bill = $this->service->adjustDueDate($supplierBill, $request->input('due_date'), $request->user());
            return back()->with('success', "Due date for Bill [{$bill->bill_code}] updated to {$bill->due_date->toDateString()}.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setToDraft(Request $request, SupplierBill $supplierBill)
    {
        try {
            // Audit H5: a posted tax document is no longer reopened on the spot —
            // this files a request (with its reason) for a DIFFERENT approver.
            app(\App\Services\Accounting\DocumentReopenService::class)->requestReopen($supplierBill, $request->user(), (string) $request->input('reason'));
            return back()->with('success', "Supplier Bill [{$supplierBill->bill_code}] — reopen request sent for approval.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Prepaid Expense Bill — "Post Next Amortization" button on a line. See PrepaidAmortizationService. */
    public function postAmortization(Request $request, \App\Models\SupplierBillLine $supplierBillLine, \App\Services\Accounting\PrepaidAmortizationService $amortization)
    {
        try {
            $amortization->postNextPeriod($supplierBillLine, $request->user());
            return back()->with('success', 'Amortization period posted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Prepaid Expense Bill — reverses one previously-posted amortization period. See PrepaidAmortizationService. */
    public function reverseAmortization(Request $request, \App\Models\PrepaidAmortizationEntry $prepaidAmortizationEntry, \App\Services\Accounting\PrepaidAmortizationService $amortization)
    {
        try {
            $amortization->reverseEntry($prepaidAmortizationEntry, $request->user());
            return back()->with('success', 'Amortization period reversed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — this supplier's other posted payments still carrying unallocated cash. Mirrors CustomerInvoiceController::unappliedCollections(). */
    public function unappliedPayments(Request $request)
    {
        $partnerId = $request->integer('partner_id');

        $payments = SupplierPayment::unallocated()
            ->where('partner_id', $partnerId)
            ->orderByDesc('payment_date')
            ->get(['id', 'payment_code', 'payment_date', 'amount', 'unallocated_amount', 'pay_currency_id'])
            ->load('payCurrency:id,code');

        return response()->json(['payments' => $payments])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /** Live lookup — posted GRN lines for one supplier still open to bill. */
    public function eligibleGrnLines(Request $request)
    {
        $partnerId = $request->integer('partner_id');
        $purchaseOrderId = $request->integer('purchase_order_id') ?: null;

        $lines = GoodsReceiptNoteLine::whereHas('goodsReceiptNote', function ($q) use ($partnerId, $purchaseOrderId) {
                $q->where('status', GoodsReceiptNote::STATUS_POSTED)->where('partner_id', $partnerId);
                if ($purchaseOrderId) {
                    $q->where('purchase_order_id', $purchaseOrderId);
                }
            })
            ->with('product:id,name_en,name_ar,cogs_account_id,inventory_account_id', 'uom:id,code', 'goodsReceiptNote:id,grn_code,received_date')
            ->get()
            ->filter(fn ($line) => bccomp($line->remainingBillableQuantity(), '0', 4) > 0)
            ->map(fn ($line) => [
                'id'                         => $line->id,
                'grn_code'                   => $line->goodsReceiptNote?->grn_code,
                'received_date'              => $line->goodsReceiptNote?->received_date?->toDateString(),
                'product_id'                 => $line->product_id,
                'product_name'               => $line->product?->name_en,
                'description'                => $line->description,
                'unit_cost'                  => (string) $line->unit_cost,
                'quantity'                   => (string) $line->quantity,
                'billed_quantity'            => (string) $line->billed_quantity,
                'remaining_billable_quantity' => $line->remainingBillableQuantity(),
                'uom_id'                     => $line->uom_id,
            ])
            ->values();

        return response()->json(['lines' => $lines]);
    }

    /** Live lookup — most recent exchange rate for a currency, as of today (or a given date). User can still override in the form. */
    public function latestExchangeRate(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencyId = $request->integer('currency_id');
        $date = $request->string('date')->toString() ?: now()->toDateString();

        $rate = \App\Models\ExchangeRate::rateFor($companyId, $currencyId, $date);

        return response()->json(['rate' => $rate]);
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        $companyId = $this->companyId($request);

        return [
            'suppliers'       => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'payment_term_id']),
            'purchaseOrders'  => PurchaseOrder::active()->where('billing_status', '!=', 'fully_billed')
                ->orderByDesc('created_at')->get(['id', 'po_code', 'partner_id']),
            'currencies'      => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId' => \App\Models\CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'expenseAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
            'products'        => $productsWithVat = Product::active()->withStock()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'cogs_account_id', 'inventory_account_id']),
            'productVatRates' => $productsWithVat->mapWithKeys(fn ($p) => [$p->id => $this->taxService->resolveVatRate($companyId, $p->id)]),
            'costCenters'     => \App\Models\CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'projects'        => \App\Models\Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en']),
            'paymentTerms'    => PaymentTerm::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'name_en']),
            'companyDefaultVatRate' => $this->taxService->getOrCreateSettings($companyId)->vat_rate,
        ];
    }
}