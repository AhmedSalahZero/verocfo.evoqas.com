<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerCreditController
//
//  HTTP layer for Accounting → Customers Receivables → Customer
//  Credits (Audit M-9, Oct 2026): the customer credit balances left
//  when a Credit Note was worth more than its invoice still owed,
//  with the two ways to settle one — apply it to an open invoice, or
//  refund it in cash — and the undo for each. Zero business logic;
//  everything is in CustomerCreditBalanceService.
//
//  Routes (accounting.customer-credits.*):
//    GET   /customer-credits                              index
//    GET   /customer-credits/{balance}/open-invoices      open invoices (JSON)
//    POST  /customer-credits/{balance}/apply              apply
//    POST  /customer-credits/{balance}/refund             refund
//    PATCH /customer-credit-applications/{application}/undo
//    PATCH /customer-refunds/{refund}/reverse
//
//  @layer    Controller
//  @depends  CustomerCreditBalanceService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ApplyCustomerCreditRequest;
use App\Http\Requests\Accounting\RefundCustomerCreditRequest;
use App\Models\CustomerCreditApplication;
use App\Models\CustomerCreditBalance;
use App\Models\CustomerRefund;
use App\Models\Partner;
use App\Services\Accounting\CustomerCreditBalanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerCreditController extends Controller
{
    public function __construct(private CustomerCreditBalanceService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/CustomerCredits/Index', array_merge([
            'balances'  => $this->service->paginate($companyId, $request->only(['status', 'partner_id', 'search'])),
            'filters'   => $request->only(['status', 'partner_id', 'search']),
            'totalOpen' => $this->service->totalOpen($companyId),
            'customers' => Partner::ofType('customer')->orderBy('name_en')->get(['id', 'code', 'name_en']),
        ], $this->service->refundSources($companyId)));
    }

    public function openInvoices(Request $request, CustomerCreditBalance $balance)
    {
        return response()->json(['invoices' => $this->service->openInvoices($balance)]);
    }

    public function apply(ApplyCustomerCreditRequest $request, CustomerCreditBalance $balance)
    {
        return $this->attempt(fn () => $this->service->apply($balance, $request->validated(), $request->user()), 'Credit balance applied to the invoice.');
    }

    public function refund(RefundCustomerCreditRequest $request, CustomerCreditBalance $balance)
    {
        return $this->attempt(fn () => $this->service->refund($balance, $request->validated(), $request->user()), 'Refund recorded.');
    }

    public function undoApplication(Request $request, CustomerCreditApplication $application)
    {
        return $this->attempt(fn () => $this->service->undoApplication($application, $request->user()), 'Application undone.');
    }

    public function reverseRefund(Request $request, CustomerRefund $refund)
    {
        $reason = $request->validate(['reversal_reason' => ['required', 'string', 'min:5', 'max:500']])['reversal_reason'];

        return $this->attempt(fn () => $this->service->reverseRefund($refund, $request->user(), $reason), 'Refund reversed.');
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
            return back()->with('success', $success);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
