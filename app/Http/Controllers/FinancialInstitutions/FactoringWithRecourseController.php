<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringWithRecourseController
//
//  HTTP layer for Factoring WITH RECOURSE only — its own dedicated
//  index/create/store/agreements lookup. Deliberately does NOT list
//  Without Recourse transactions, and does NOT offer a "New —
//  Without Recourse" shortcut — the two are different products with
//  different lifecycles (Without Recourse has no Reject) and must
//  never look like they share one screen. See
//  FactoringWithoutRecourseController for its sibling, and
//  FactoringTransactionController for the actions genuinely shared
//  across both types (Collect/Reject/Set to Draft/Delete/the
//  customers-invoices-calculate lookups).
//
//  Routes (prefix: financial-institutions, name: fi.):
//    GET  /factoring-with-recourse                               → fi.factoring-with-recourse.index
//    GET  /factoring-with-recourse/create                        → fi.factoring-with-recourse.create
//    POST /factoring-with-recourse                                → fi.factoring-with-recourse.store
//    GET  /factoring-with-recourse-lookup/agreements/{factoringCompany} → fi.factoring-with-recourse.agreements
//
//  @layer    Controller
//  @depends  FactoringTransactionService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\StoreFactoringTransactionRequest;
use App\Services\FinancialInstitutions\FactoringTransactionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FactoringWithRecourseController extends Controller
{
    public function __construct(private FactoringTransactionService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/FactoringWithRecourseIndex', [
            'transactions' => $this->service->paginate($companyId, 'with_recourse', $request->only('status')),
            'bankAccounts' => $this->service->activeBankAccounts($companyId),
            'filters'      => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Treasury/FactoringWithRecourseForm', [
            'factoringCompanies' => $this->service->factoringCompaniesForCompany($companyId),
            'bankAccounts'       => $this->service->activeBankAccounts($companyId),
        ]);
    }

    public function store(StoreFactoringTransactionRequest $request)
    {
        $data = $request->validated();

        try {
            $transaction = $this->service->createWithRecourse($this->companyId($request), $data, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['customer_invoice_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('fi.factoring-with-recourse.index')
            ->with('success', "Factoring transaction created — invoice {$transaction->customerInvoice->invoice_code} disbursed successfully.");
    }

    public function agreements(Request $request, int $factoringCompany)
    {
        return response()->json(
            $this->service->eligibleAgreements($this->companyId($request), $factoringCompany, 'with_recourse')
        );
    }
}
