<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CreditNoteController
//
//  HTTP layer for Credit Notes. Zero business logic — delegated to
//  CreditNoteService.
//
//  eligibleInvoices() / invoiceLines() — live JSON endpoints the
//  Credit Note form calls: first pick a posted, still-open invoice
//  for a customer, then pick which of its lines to credit.
//
//  Routes (under auth + active.company):
//    GET    /accounting/credit-notes                      → index()
//    GET    /accounting/credit-notes/create                → create()
//    POST   /accounting/credit-notes                       → store()
//    GET    /accounting/credit-notes/{creditNote}/edit      → edit()
//    PUT    /accounting/credit-notes/{creditNote}           → update()
//    DELETE /accounting/credit-notes/{creditNote}           → destroy()
//    PATCH  /accounting/credit-notes/{creditNote}/post      → post()
//    PATCH  /accounting/credit-notes/{creditNote}/cancel    → cancel()
//    GET    /accounting/credit-notes/eligible-invoices      → eligibleInvoices() (JSON)
//    GET    /accounting/credit-notes/invoice-lines          → invoiceLines() (JSON)
//
//  @layer    Controller
//  @depends  CreditNoteService, CreditNote, CreditNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CreditNoteRequest;
use App\Models\CreditNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Services\Accounting\CreditNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CreditNoteController extends Controller
{
    public function __construct(private CreditNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/CreditNotes/Index', [
            'creditNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'     => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    /**
     * Optional ?partner_id= & ?invoice_id= — arrives from the "Credit
     * Note" button on the Invoices Report (Customer Balances). Same
     * pattern as CustomerPaymentController::create()'s ?invoice_id=.
     * Both silently ignored if invalid — the form then opens exactly
     * as it does today.
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

        return Inertia::render('Accounting/CreditNotes/Form', [
            'creditNote' => null,
            'customers'  => $customers,
            'preselectedPartnerId'  => $preselectedPartnerId,
            'preselectedInvoiceId'  => $preselectedInvoiceId,
        ]);
    }

    public function store(CreditNoteRequest $request)
    {
        try {
            $creditNote = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.credit-notes.index')->with('success', "Credit Note [{$creditNote->credit_note_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, CreditNote $creditNote)
    {
        return Inertia::render('Accounting/CreditNotes/Form', [
            'creditNote' => $creditNote->load([
                'partner:id,name_en', 'customerInvoice:id,invoice_code', 'currency:id,code',
                'lines.customerInvoiceLine:id,description,line_subtotal,vat_amount,line_total',
            ]),
            'customers' => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function update(CreditNoteRequest $request, CreditNote $creditNote)
    {
        try {
            $this->service->update($this->companyId($request), $creditNote, $request->validated(), $request->user());
            return redirect()->route('accounting.credit-notes.index')->with('success', 'Credit Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, CreditNote $creditNote)
    {
        try {
            $this->service->delete($creditNote, $request->user());
            return redirect()->route('accounting.credit-notes.index')->with('success', 'Draft Credit Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, CreditNote $creditNote)
    {
        try {
            $cn = $this->service->post($creditNote, $request->user());
            return back()->with('success', "Credit Note [{$cn->credit_note_code}] posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, CreditNote $creditNote)
    {
        try {
            $this->service->cancel($creditNote, $request->user());
            return back()->with('success', "Credit Note [{$creditNote->credit_note_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Audit M9 — undo a posted Credit Note (mirrors the entry, restores the invoice balance). */
    public function reverse(Request $request, CreditNote $creditNote)
    {
        $reason = $request->validate(['reversal_reason' => ['required', 'string', 'min:5', 'max:500']])['reversal_reason'];

        try {
            $this->service->reverse($creditNote, $request->user(), $reason);
            return back()->with('success', "Credit Note [{$creditNote->credit_note_code}] reversed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — a customer's posted invoices (paid ones included — see Audit M9). */
    public function eligibleInvoices(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id') ?: null;

        // Audit M9 — a paid invoice can be credited too (the surplus becomes a
        // customer credit balance), so every posted invoice is offered.
        $invoices = CustomerInvoice::where('status', CustomerInvoice::STATUS_POSTED)
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderByDesc('invoice_date')
            ->get(['id', 'invoice_code', 'partner_id', 'invoice_date', 'invoice_value_functional', 'amount_outstanding', 'currency_id', 'exchange_rate']);

        return response()->json(['invoices' => $invoices]);
    }

    /** Live lookup — every line on a specific invoice, for the credit note line picker. */
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
