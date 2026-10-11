<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringWithoutRecourseController
//
//  HTTP layer for Factoring WITHOUT RECOURSE only — its own dedicated
//  index/create/store/agreements lookup, fully separate from
//  FactoringWithRecourseController. Deliberately does NOT list With
//  Recourse transactions, and never offers a Reject action (see
//  FactoringTransactionService::markRejected() — Without Recourse has
//  no repayment obligation, risk already transferred). See
//  FactoringTransactionController for the actions genuinely shared
//  across both types (Collect/Set to Draft/Delete/the
//  customers-invoices-calculate lookups).
//
//  Routes (prefix: financial-institutions, name: fi.):
//    GET  /factoring-without-recourse                               → fi.factoring-without-recourse.index
//    GET  /factoring-without-recourse/create                        → fi.factoring-without-recourse.create
//    POST /factoring-without-recourse                                → fi.factoring-without-recourse.store
//    GET  /factoring-without-recourse-lookup/agreements/{factoringCompany} → fi.factoring-without-recourse.agreements
//
//  @layer    Controller
//  @depends  FactoringTransactionService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\StoreFactoringTransactionRequest;
use App\Services\FinancialInstitutions\FactoringTransactionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FactoringWithoutRecourseController extends Controller
{
    public function __construct(private FactoringTransactionService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/FactoringWithoutRecourseIndex', [
            'transactions' => $this->service->paginate($companyId, 'without_recourse', $request->only('status')),
            'bankAccounts' => $this->service->activeBankAccounts($companyId),
            'filters'      => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/FactoringWithoutRecourseForm', [
            'factoringCompanies' => $this->service->factoringCompaniesForCompany($companyId),
            'bankAccounts'       => $this->service->activeBankAccounts($companyId),
        ]);
    }

    public function store(StoreFactoringTransactionRequest $request)
    {
        $data = $request->validated();

        try {
            $transaction = $this->service->createWithoutRecourse($this->companyId($request), $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['customer_invoice_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('fi.factoring-without-recourse.index')
            ->with('success', "Factoring transaction created — invoice {$transaction->customerInvoice->invoice_code} sold without recourse and tagged paid.");
    }

    public function agreements(Request $request, int $factoringCompany)
    {
        return response()->json(
            $this->service->eligibleAgreements($this->companyId($request), $factoringCompany, 'without_recourse')
        );
    }
}
