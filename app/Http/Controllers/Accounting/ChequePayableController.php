<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ChequePayableController
//
//  HTTP layer for a payable cheque's lifecycle beyond issue. Zero
//  business logic — delegated to ChequePayableService. Deliberately
//  simpler than ChequeReceivableController — Mark as Paid + Reset
//  only (confirmed by Mahmoud), no send-for-collection or bounce.
//
//  Routes (under auth + active.company):
//    GET    /accounting/cheques-payable                            → index()
//    POST   /accounting/cheques-payable/{cheque}/mark-as-paid       → markAsPaid() (JSON)
//    POST   /accounting/cheques-payable/{cheque}/reset-payment      → resetPayment() (JSON)
//
//  @layer    Controller
//  @depends  ChequePayableService, ChequePayable
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ChequePayable;
use App\Services\Accounting\ChequePayableService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChequePayableController extends Controller
{
    public function __construct(private ChequePayableService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $status = $request->string('status')->toString() ?: null;

        return Inertia::render('Accounting/ChequesPayable/Index', [
            'cheques' => ChequePayable::where('company_id', $companyId)
                ->with(['partner:id,name_en', 'supplierPayment:id,payment_code', 'otherPayment:id,payment_code,partner_type', 'cashExpensePayment:id,payment_code'])
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('due_date')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['status' => $status],
        ]);
    }

    public function markAsPaid(Request $request, ChequePayable $chequePayable)
    {
        try {
            $cheque = $this->service->markAsPaid($chequePayable, $request->string('payment_date')->toString(), $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Cheque marked as paid.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function resetPayment(Request $request, ChequePayable $chequePayable)
    {
        try {
            $cheque = $this->service->resetPayment($chequePayable, $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Payment reset.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function voidCheque(Request $request, ChequePayable $chequePayable)
    {
        try {
            $cheque = $this->service->voidCheque($chequePayable, $request->string('kind')->toString(), $request->string('reason')->toString(), $request->string('date', now()->toDateString())->toString(), $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Cheque recorded as '.$cheque->status.'. To reverse the accounting, reopen or cancel the payment that issued it.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
