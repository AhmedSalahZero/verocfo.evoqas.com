<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierDebitNoteController
//
//  HTTP layer for Supplier Debit Notes. Zero business logic —
//  delegated to SupplierDebitNoteService.
//
//  eligibleBills() / billLines() — live JSON endpoints the Debit Note
//  form calls: pick a posted, still-open bill for a supplier, then
//  pick which of its lines to reverse. billLines() only returns
//  Fresh Expense / Accrual Settlement lines — Goods lines are
//  filtered out here so the form never offers something the service
//  would reject anyway (see SupplierDebitNoteService header for why
//  goods lines aren't supported yet).
//
//  Routes (under auth + active.company):
//    GET    /accounting/supplier-debit-notes                        → index()
//    GET    /accounting/supplier-debit-notes/create                 → create()
//    POST   /accounting/supplier-debit-notes                        → store()
//    GET    /accounting/supplier-debit-notes/{supplierDebitNote}/edit → edit()
//    PUT    /accounting/supplier-debit-notes/{supplierDebitNote}     → update()
//    DELETE /accounting/supplier-debit-notes/{supplierDebitNote}     → destroy()
//    PATCH  /accounting/supplier-debit-notes/{supplierDebitNote}/post   → post()
//    PATCH  /accounting/supplier-debit-notes/{supplierDebitNote}/cancel → cancel()
//    GET    /accounting/supplier-debit-notes/eligible-bills          → eligibleBills() (JSON)
//    GET    /accounting/supplier-debit-notes/bill-lines              → billLines() (JSON)
//
//  @layer    Controller
//  @depends  SupplierDebitNoteService, SupplierDebitNote, SupplierDebitNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SupplierDebitNoteRequest;
use App\Models\Partner;
use App\Models\SupplierBill;
use App\Models\SupplierDebitNote;
use App\Services\Accounting\SupplierDebitNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierDebitNoteController extends Controller
{
    public function __construct(private SupplierDebitNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/SupplierDebitNotes/Index', [
            'debitNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'    => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    /**
     * Optional ?partner_id= & ?bill_id= — arrives from the "Debit
     * Note" button on the Invoices Report (Supplier Balances). Same
     * pattern as SupplierCreditNoteController::create().
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

        return Inertia::render('Accounting/SupplierDebitNotes/Form', [
            'debitNote' => null,
            'suppliers' => $suppliers,
            'preselectedPartnerId' => $preselectedPartnerId,
            'preselectedBillId'    => $preselectedBillId,
        ]);
    }

    public function store(SupplierDebitNoteRequest $request)
    {
        try {
            $debitNote = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-debit-notes.index')->with('success', "Debit Note [{$debitNote->debit_note_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, SupplierDebitNote $supplierDebitNote)
    {
        return Inertia::render('Accounting/SupplierDebitNotes/Form', [
            'debitNote' => $supplierDebitNote->load([
                'partner:id,name_en', 'supplierBill:id,bill_code', 'currency:id,code',
                'lines.supplierBillLine:id,description,line_subtotal,vat_amount,line_total',
            ]),
            'suppliers' => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(SupplierDebitNoteRequest $request, SupplierDebitNote $supplierDebitNote)
    {
        try {
            $this->service->update($this->companyId($request), $supplierDebitNote, $request->validated(), $request->user());
            return redirect()->route('accounting.supplier-debit-notes.index')->with('success', 'Debit Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, SupplierDebitNote $supplierDebitNote)
    {
        try {
            $this->service->delete($supplierDebitNote, $request->user());
            return redirect()->route('accounting.supplier-debit-notes.index')->with('success', 'Draft Debit Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, SupplierDebitNote $supplierDebitNote)
    {
        try {
            $dn = $this->service->post($supplierDebitNote, $request->user());
            return back()->with('success', "Debit Note [{$dn->debit_note_code}] posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierDebitNote $supplierDebitNote)
    {
        try {
            $this->service->cancel($supplierDebitNote, $request->user());
            return back()->with('success', "Debit Note [{$supplierDebitNote->debit_note_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — posted bills still carrying an open balance, for a supplier. */
    public function eligibleBills(Request $request)
    {
        $partnerId = $request->integer('partner_id') ?: null;

        $bills = SupplierBill::open()
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderByDesc('bill_date')
            ->get(['id', 'bill_code', 'partner_id', 'bill_date', 'bill_value_functional', 'amount_outstanding', 'currency_id', 'exchange_rate']);

        return response()->json(['bills' => $bills]);
    }

    /**
     * Live lookup — Fresh Expense / Accrual Settlement lines on a
     * specific bill only. Goods lines are deliberately left out — see
     * class header and SupplierDebitNoteService header.
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
