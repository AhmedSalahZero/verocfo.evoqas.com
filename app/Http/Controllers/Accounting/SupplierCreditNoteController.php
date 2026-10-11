<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierCreditNoteController
//
//  HTTP layer for Supplier Credit Notes. Zero business logic —
//  delegated to SupplierCreditNoteService. Same shape as
//  SupplierDebitNoteController, opposite direction — see that
//  controller's header for the goods-line filtering rationale.
//
//  Routes (under auth + active.company):
//    GET    /accounting/supplier-credit-notes                         → index()
//    GET    /accounting/supplier-credit-notes/create                  → create()
//    POST   /accounting/supplier-credit-notes                         → store()
//    GET    /accounting/supplier-credit-notes/{supplierCreditNote}/edit → edit()
//    PUT    /accounting/supplier-credit-notes/{supplierCreditNote}     → update()
//    DELETE /accounting/supplier-credit-notes/{supplierCreditNote}     → destroy()
//    PATCH  /accounting/supplier-credit-notes/{supplierCreditNote}/post   → post()
//    PATCH  /accounting/supplier-credit-notes/{supplierCreditNote}/cancel → cancel()
//    GET    /accounting/supplier-credit-notes/eligible-bills           → eligibleBills() (JSON)
//    GET    /accounting/supplier-credit-notes/bill-lines               → billLines() (JSON)
//
//  @layer    Controller
//  @depends  SupplierCreditNoteService, SupplierCreditNote, SupplierCreditNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SupplierCreditNoteRequest;
use App\Models\Partner;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Services\Accounting\SupplierCreditNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierCreditNoteController extends Controller
{
    public function __construct(private SupplierCreditNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/SupplierCreditNotes/Index', [
            'creditNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'     => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    /**
     * Optional ?partner_id= & ?bill_id= — arrives from the "Credit
     * Note" button on the Invoices Report (Supplier Balances). Same
     * pattern as CreditNoteController::create() on the customer side.
     */
    public function create(Request $request)
    {
        $suppliers = Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']);

        $preselectedPartnerId = $request->integer('partner_id') ?: null;
        $preselectedBillId = $request->integer('bill_id') ?: null;

        if ($preselectedPartnerId && ! $suppliers->contains('id', $preselectedPartnerId)) {
            $preselectedPartnerId = null;
            $preselectedBillId = null;
        }

        return Inertia::render('Accounting/SupplierCreditNotes/Form', [
            'creditNote' => null,
            'suppliers'  => $suppliers,
            'preselectedPartnerId' => $preselectedPartnerId,
            'preselectedBillId'    => $preselectedBillId,
        ]);
    }

    public function store(SupplierCreditNoteRequest $request)
    {
        try {
            $creditNote = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-credit-notes.index')->with('success', "Credit Note [{$creditNote->credit_note_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, SupplierCreditNote $supplierCreditNote)
    {
        return Inertia::render('Accounting/SupplierCreditNotes/Form', [
            'creditNote' => $supplierCreditNote->load([
                'partner:id,name_en', 'supplierBill:id,bill_code', 'currency:id,code',
                'lines.supplierBillLine:id,description,line_subtotal,vat_amount,line_total',
            ]),
            'suppliers' => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(SupplierCreditNoteRequest $request, SupplierCreditNote $supplierCreditNote)
    {
        try {
            $this->service->update($this->companyId($request), $supplierCreditNote, $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-credit-notes.index')->with('success', 'Credit Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, SupplierCreditNote $supplierCreditNote)
    {
        try {
            $this->service->delete($supplierCreditNote, $request->user());
            return redirect()->route('accounting.supplier-credit-notes.index')->with('success', 'Draft Credit Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, SupplierCreditNote $supplierCreditNote)
    {
        try {
            $cn = $this->service->post($supplierCreditNote, $request->user());
            return back()->with('success', "Credit Note [{$cn->credit_note_code}] posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierCreditNote $supplierCreditNote)
    {
        try {
            $this->service->cancel($supplierCreditNote, $request->user());
            return back()->with('success', "Credit Note [{$supplierCreditNote->credit_note_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — posted bills for a supplier (a Credit Note has no "open balance" gate — see service header). */
    public function eligibleBills(Request $request)
    {
        $partnerId = $request->integer('partner_id') ?: null;

        $bills = SupplierBill::posted()
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderByDesc('bill_date')
            ->get(['id', 'bill_code', 'partner_id', 'bill_date', 'bill_value_functional', 'amount_outstanding', 'currency_id', 'exchange_rate']);

        return response()->json(['bills' => $bills]);
    }

    /**
     * Live lookup — Fresh Expense / Accrual Settlement lines on a
     * specific bill only. Goods lines are deliberately left out — see
     * SupplierDebitNoteController's header for the shared rationale.
     */
    public function billLines(Request $request)
    {
        $billId = $request->integer('supplier_bill_id');

        $bill = SupplierBill::with('lines')->findOrFail($billId);

        return response()->json([
            'bill'  => $bill->only(['id', 'bill_code', 'amount_outstanding', 'currency_id', 'exchange_rate']),
            'lines' => $bill->lines->reject->isGoodsLine()->values()->map(fn ($l) => [
                'id'            => $l->id,
                'description'   => $l->description,
                'quantity'      => (string) $l->quantity,
                'unit_price'    => (string) $l->unit_price,
                'vat_rate'      => (string) $l->vat_rate,
                'line_total'    => (string) $l->line_total,
            ]),
        ]);
    }
}
