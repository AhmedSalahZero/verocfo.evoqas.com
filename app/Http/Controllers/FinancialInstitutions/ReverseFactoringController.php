<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ReverseFactoringController
//
//  HTTP layer for Reverse Factoring transactions (Treasury
//  Operations) — its own dedicated index/create/store, same pattern
//  as FactoringWithRecourseController/FactoringWithoutRecourseController.
//  Zero business logic — all delegated to ReverseFactoringTransactionService.
//
//  Routes (prefix: financial-institutions, name: fi.):
//    GET    /reverse-factoring                              → fi.reverse-factoring.index
//    GET    /reverse-factoring/create                       → fi.reverse-factoring.create
//    POST   /reverse-factoring                                → fi.reverse-factoring.store
//    GET    /reverse-factoring-lookup/agreements/{factoringCompany} → fi.reverse-factoring.agreements
//    GET    /reverse-factoring-lookup/suppliers/{agreement}  → fi.reverse-factoring.suppliers
//    GET    /reverse-factoring-lookup/bills/{partnerId}      → fi.reverse-factoring.bills
//    POST   /reverse-factoring-lookup/calculate               → fi.reverse-factoring.calculate
//    POST   /reverse-factoring/{transaction}/settle           → fi.reverse-factoring.settle
//    DELETE /reverse-factoring/{transaction}/settle           → fi.reverse-factoring.settle.undo
//    POST   /reverse-factoring/{transaction}/set-to-draft     → fi.reverse-factoring.set-to-draft
//    DELETE /reverse-factoring/{transaction}                  → fi.reverse-factoring.destroy
//
//  @layer    Controller
//  @depends  ReverseFactoringTransactionService, FactoringAgreement
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\StoreReverseFactoringTransactionRequest;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\ReverseFactoringTransaction;
use App\Services\FinancialInstitutions\ReverseFactoringTransactionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReverseFactoringController extends Controller
{
    public function __construct(private ReverseFactoringTransactionService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/ReverseFactoringIndex', [
            'transactions' => $this->service->paginate($companyId, $request->only('status')),
            'bankAccounts' => $this->service->activeBankAccounts($companyId),
            'filters'      => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/ReverseFactoringForm', [
            'factoringCompanies' => $this->service->factoringCompaniesForCompany($companyId),
            'bankAccounts'       => $this->service->activeBankAccounts($companyId),
        ]);
    }

    public function store(StoreReverseFactoringTransactionRequest $request)
    {
        $data = $request->validated();

        try {
            $transaction = $this->service->createReverseFactoring($this->companyId($request), $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['supplier_bill_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('fi.reverse-factoring.index')
            ->with('success', "Reverse factoring transaction created — bill {$transaction->supplierBill->bill_code} disbursed successfully.");
    }

    // ── Cascading lookups ────────────────────────────────────────

    public function agreements(Request $request, int $factoringCompany)
    {
        return response()->json(
            $this->service->eligibleAgreements($this->companyId($request), $factoringCompany)
        );
    }

    public function suppliers(Request $request, FactoringAgreement $agreement)
    {
        return response()->json(
            $this->service->eligibleSuppliers($this->companyId($request), $agreement)
        );
    }

    public function bills(Request $request, int $partnerId)
    {
        return response()->json(
            $this->service->eligibleBills($this->companyId($request), $partnerId)
        );
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'supplier_bill_id'             => ['required', 'integer'],
            'factoring_agreement_id'       => ['required', 'integer'],
            'reverse_factoring_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'reverse_factoring_charges'    => ['nullable', 'numeric', 'min:0'],
            'reverse_factoring_date'       => ['required', 'date'],
            'reverse_due_date'             => ['nullable', 'date'],
        ]);

        return response()->json($this->service->calculate(
            $this->companyId($request),
            $data['supplier_bill_id'],
            $data['factoring_agreement_id'],
            (string) $data['reverse_factoring_percentage'],
            (string) ($data['reverse_factoring_charges'] ?? 0),
            $data['reverse_factoring_date'],
            $data['reverse_due_date'] ?? null
        ));
    }

    // ── Settle (due-date path) ───────────────────────────────────

    public function settle(Request $request, ReverseFactoringTransaction $transaction)
    {
        $data = $request->validate([
            'settlement_date' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
        ]);

        try {
            $this->service->settle($this->companyId($request), $transaction, $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['settlement_date' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Reverse factoring transaction settled.');
    }

    public function undoSettle(Request $request, ReverseFactoringTransaction $transaction)
    {
        try {
            $this->service->revertSettle($this->companyId($request), $transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['settlement' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Settlement reverted — transaction is Pending again.');
    }

    // ── Set to Draft / Delete ────────────────────────────────────

    public function setToDraft(Request $request, ReverseFactoringTransaction $transaction)
    {
        try {
            $this->service->setToDraft($this->companyId($request), $transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Transaction reopened to draft — the disbursement has been reversed.');
    }

    public function destroy(Request $request, ReverseFactoringTransaction $transaction)
    {
        try {
            $this->service->delete($transaction, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Draft reverse factoring transaction deleted.');
    }
}
