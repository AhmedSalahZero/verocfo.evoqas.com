<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerDebitNoteController
//
//  HTTP layer for Customer Debit Notes. Zero business logic —
//  delegated to CustomerDebitNoteService. Mirrors CreditNoteController
//  exactly, opposite direction.
//
//  eligibleInvoices() / invoiceLines() — same live JSON endpoints as
//  Credit Note's form uses: pick a posted invoice for a customer,
//  then pick which of its lines to add to.
//
//  Routes (under auth + active.company):
//    GET    /accounting/customer-debit-notes                       → index()
//    GET    /accounting/customer-debit-notes/create                → create()
//    POST   /accounting/customer-debit-notes                       → store()
//    GET    /accounting/customer-debit-notes/{customerDebitNote}/edit → edit()
//    PUT    /accounting/customer-debit-notes/{customerDebitNote}    → update()
//    DELETE /accounting/customer-debit-notes/{customerDebitNote}    → destroy()
//    PATCH  /accounting/customer-debit-notes/{customerDebitNote}/post   → post()
//    PATCH  /accounting/customer-debit-notes/{customerDebitNote}/cancel → cancel()
//    GET    /accounting/customer-debit-notes/eligible-invoices      → eligibleInvoices() (JSON)
//    GET    /accounting/customer-debit-notes/invoice-lines          → invoiceLines() (JSON)
//
//  @layer    Controller
//  @depends  CustomerDebitNoteService, CustomerDebitNote, CustomerDebitNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CustomerDebitNoteRequest;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Services\Accounting\CustomerDebitNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerDebitNoteController extends Controller
{
    public function __construct(private CustomerDebitNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/CustomerDebitNotes/Index', [
            'debitNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'    => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    /**
     * Optional ?partner_id= & ?invoice_id= — arrives from the "Debit
     * Note" button on the Invoices Report (Customer Balances). Same
     * pattern as CreditNoteController::create().
     */
    public function create(Request $request)
    {
        $customers = Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']);

        $preselectedPartnerId = $request->integer('partner_id') ?: null;
        $preselectedInvoiceId = $request->integer('invoice_id') ?: null;

        if ($preselectedPartnerId && ! $customers->contains('id', $preselectedPartnerId)) {
            $preselectedPartnerId = null;
            $preselectedInvoiceId = null;
        }

        return Inertia::render('Accounting/CustomerDebitNotes/Form', [
            'debitNote' => null,
            'customers' => $customers,
            'preselectedPartnerId'  => $preselectedPartnerId,
            'preselectedInvoiceId'  => $preselectedInvoiceId,
        ]);
    }

    public function store(CustomerDebitNoteRequest $request)
    {
        try {
            $debitNote = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.customer-debit-notes.index')->with('success', "Debit Note [{$debitNote->debit_note_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, CustomerDebitNote $customerDebitNote)
    {
        return Inertia::render('Accounting/CustomerDebitNotes/Form', [
            'debitNote' => $customerDebitNote->load([
                'partner:id,name_en', 'customerInvoice:id,invoice_code', 'currency:id,code',
                'lines.customerInvoiceLine:id,description,line_subtotal,vat_amount,line_total',
            ]),
            'customers' => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(CustomerDebitNoteRequest $request, CustomerDebitNote $customerDebitNote)
    {
        try {
            $this->service->update($this->companyId($request), $customerDebitNote, $request->validated(), $request->user());
            return redirect()->route('accounting.customer-debit-notes.index')->with('success', 'Debit Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, CustomerDebitNote $customerDebitNote)
    {
        try {
            $this->service->delete($customerDebitNote, $request->user());
            return redirect()->route('accounting.customer-debit-notes.index')->with('success', 'Draft Debit Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, CustomerDebitNote $customerDebitNote)
    {
        try {
            $dn = $this->service->post($customerDebitNote, $request->user());
            return back()->with('success', "Debit Note [{$dn->debit_note_code}] posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, CustomerDebitNote $customerDebitNote)
    {
        try {
            $this->service->cancel($customerDebitNote, $request->user());
            return back()->with('success', "Debit Note [{$customerDebitNote->debit_note_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — posted invoices for a customer (a Debit Note has no "open balance" gate — see service header). */
    public function eligibleInvoices(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id') ?: null;

        $invoices = CustomerInvoice::posted()
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderByDesc('invoice_date')
            ->get(['id', 'invoice_code', 'partner_id', 'invoice_date', 'invoice_value_functional', 'amount_outstanding', 'currency_id', 'exchange_rate']);

        return response()->json(['invoices' => $invoices]);
    }

    /** Live lookup — every line on a specific invoice, for the debit note line picker. */
    public function invoiceLines(Request $request)
    {
        $invoiceId = $request->integer('customer_invoice_id');

        $invoice = CustomerInvoice::with('lines.product:id,name_en')->findOrFail($invoiceId);

        return response()->json([
            'invoice' => $invoice->only(['id', 'invoice_code', 'amount_outstanding', 'currency_id', 'exchange_rate']),
            'lines'   => $invoice->lines->map(fn ($l) => [
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
