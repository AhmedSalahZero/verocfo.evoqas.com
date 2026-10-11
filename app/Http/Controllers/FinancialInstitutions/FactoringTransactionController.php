<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringTransactionController
//
//  Neutral HTTP layer for actions that operate on a FactoringTransaction
//  regardless of its recourse type — Collect/Reject/Set to Draft/
//  Delete, plus the customers/invoices/calculate lookups both Create
//  forms call. Split out from FactoringWithRecourseController per
//  Mahmoud: With Recourse and Without Recourse are different products
//  with their own dedicated index/create/store, and must never look
//  like they share one screen — but the actual per-transaction
//  actions genuinely ARE identical regardless of type (the branching
//  already lives in FactoringTransactionService, not here), so
//  duplicating this controller for each type would just be dead
//  weight. Zero business logic — all delegated to the Service.
//
//  Routes (prefix: financial-institutions, name: fi.):
//    GET    /factoring-transactions-lookup/customers/{agreement}    → fi.factoring-transactions.customers
//    GET    /factoring-transactions-lookup/invoices/{partnerId}     → fi.factoring-transactions.invoices
//    POST   /factoring-transactions-lookup/calculate                → fi.factoring-transactions.calculate
//    POST   /factoring-transactions/{transaction}/collect           → fi.factoring-transactions.collect
//    DELETE /factoring-transactions/{transaction}/collect           → fi.factoring-transactions.collect.undo
//    POST   /factoring-transactions/{transaction}/reject            → fi.factoring-transactions.reject
//    DELETE /factoring-transactions/{transaction}/reject            → fi.factoring-transactions.reject.undo
//    POST   /factoring-transactions/{transaction}/set-to-draft      → fi.factoring-transactions.set-to-draft
//    DELETE /factoring-transactions/{transaction}                   → fi.factoring-transactions.destroy
//
//  @layer    Controller
//  @depends  FactoringTransactionService, FactoringAgreement, FactoringTransaction
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\MarkFactoringCollectedRequest;
use App\Http\Requests\FinancialInstitutions\MarkFactoringRejectedRequest;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\FactoringTransaction;
use App\Services\FinancialInstitutions\FactoringTransactionService;
use Illuminate\Http\Request;

class FactoringTransactionController extends Controller
{
    public function __construct(private FactoringTransactionService $service) {}

    // ── Cascading lookups (shared by both Create forms) ────────────

    public function customers(Request $request, FactoringAgreement $agreement)
    {
        return response()->json(
            $this->service->eligibleCustomers($this->companyId($request), $agreement)
        );
    }

    public function invoices(Request $request, int $partnerId)
    {
        return response()->json(
            $this->service->eligibleInvoices($this->companyId($request), $partnerId)
        );
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'customer_invoice_id'    => ['required', 'integer'],
            'factoring_agreement_id' => ['required', 'integer'],
            'factoring_percentage'   => ['required', 'numeric', 'min:0', 'max:100'],
            'other_charges'          => ['nullable', 'numeric', 'min:0'],
            'factoring_date'         => ['required', 'date'],
        ]);

        return response()->json($this->service->calculate(
            $this->companyId($request),
            $data['customer_invoice_id'],
            $data['factoring_agreement_id'],
            (string) $data['factoring_percentage'],
            (string) ($data['other_charges'] ?? 0),
            $data['factoring_date']
        ));
    }

    // ── Collect ──────────────────────────────────────────────────

    public function collect(MarkFactoringCollectedRequest $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->markCollected($this->companyId($request), $transaction, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['collection_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Factoring transaction marked as Collected.');
    }

    public function undoCollect(Request $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->revertCollected($this->companyId($request), $transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['collection' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Collection reverted — transaction is Pending again.');
    }

    // ── Reject (With Recourse only — Service refuses otherwise) ────

    public function reject(MarkFactoringRejectedRequest $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->markRejected($this->companyId($request), $transaction, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['rejection_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Factoring transaction marked as Rejected.');
    }

    public function undoReject(Request $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->revertRejected($this->companyId($request), $transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['rejection' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Rejection reverted — transaction is Pending again.');
    }

    // ── Set to Draft / Delete ────────────────────────────────────

    public function setToDraft(Request $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->setToDraft($this->companyId($request), $transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Transaction reopened to draft — the disbursement has been reversed.');
    }

    public function destroy(Request $request, FactoringTransaction $transaction)
    {
        try {
            $this->service->delete($transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Draft factoring transaction deleted.');
    }
}
