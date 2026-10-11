<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ChequeReceivableController
//
//  HTTP layer for the cheque lifecycle beyond receipt. Zero business
//  logic — delegated to ChequeReceivableService. See
//  VeroCFO_Customer_Collection_Design_v1_0.md Section 6.
//
//  Routes (under auth + active.company):
//    GET    /accounting/cheques-receivable                                → index()
//    POST   /accounting/cheques-receivable/{cheque}/send-for-collection   → sendForCollection() (JSON)
//    POST   /accounting/cheques-receivable/{cheque}/return-to-safe        → returnToSafe() (JSON)
//    POST   /accounting/cheques-receivable/{cheque}/collect               → collect() (JSON)
//    POST   /accounting/cheques-receivable/{cheque}/undo-collect          → undoCollect() (JSON)
//    POST   /accounting/cheques-receivable/{cheque}/reject                → reject() (JSON)
//
//  @layer    Controller
//  @depends  ChequeReceivableService, ChequeReceivable
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ChequeReceivable;
use App\Services\Accounting\ChequeReceivableService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChequeReceivableController extends Controller
{
    public function __construct(private ChequeReceivableService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $status = $request->string('status')->toString() ?: null;

        return Inertia::render('Accounting/ChequesReceivable/Index', [
            // customerPayment / otherCollection are mutually exclusive per
            // row (migration 000173) — eager-load both, the Vue Index
            // shows whichever is present as the cheque's "Source".
            'cheques' => ChequeReceivable::where('company_id', $companyId)
                ->with(['partner:id,name_en', 'draweeBank:id,name_en', 'customerPayment:id,payment_code', 'otherCollection:id,collection_code,partner_type', 'receivingSafeAccount:id,name_en'])
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('due_date')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['status' => $status],
        ]);
    }

    public function sendForCollection(Request $request, ChequeReceivable $chequeReceivable)
    {
        try {
            $cheque = $this->service->sendForCollection($chequeReceivable, $request->only('collection_destination_type', 'destination_id', 'sent_for_collection_at'), $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Sent for collection.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function returnToSafe(Request $request, ChequeReceivable $chequeReceivable)
    {
        try {
            $cheque = $this->service->returnToSafe($chequeReceivable, $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Returned to safe.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function collect(Request $request, ChequeReceivable $chequeReceivable)
    {
        try {
            $cheque = $this->service->collect($chequeReceivable, $request->string('collection_date')->toString(), $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Cheque collected successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function undoCollect(Request $request, ChequeReceivable $chequeReceivable)
    {
        try {
            $cheque = $this->service->undoCollect($chequeReceivable, $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Collection undone.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, ChequeReceivable $chequeReceivable)
    {
        try {
            $cheque = $this->service->reject($chequeReceivable, $request->string('rejection_date')->toString(), $request->user());
            return response()->json(['cheque' => $cheque, 'message' => 'Cheque rejected — AR reopened.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
