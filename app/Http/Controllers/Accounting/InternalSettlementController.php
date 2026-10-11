<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlementController
//
//  HTTP layer for Internal Settlement (per Mahmoud, September 2026).
//  Zero business logic — delegated to InternalSettlementService.
//  Same shape as CustomerPaymentController: record() posts
//  immediately (no draft-then-post two-step); setToDraft() is the
//  only way a posted settlement becomes editable again.
//
//  Routes (under auth + active.company):
//    GET    /accounting/internal-settlements                               → index()
//    GET    /accounting/internal-settlements/create                        → create()
//    GET    /accounting/internal-settlements/eligible-partners              → eligiblePartners() (JSON)
//    GET    /accounting/internal-settlements/open-invoices-and-bills        → openInvoicesAndBills() (JSON)
//    POST   /accounting/internal-settlements                               → store()
//    GET    /accounting/internal-settlements/{internalSettlement}/edit      → edit()
//    PUT    /accounting/internal-settlements/{internalSettlement}           → update()
//    DELETE /accounting/internal-settlements/{internalSettlement}           → destroy()
//    PATCH  /accounting/internal-settlements/{internalSettlement}/set-to-draft → setToDraft()
//    PATCH  /accounting/internal-settlements/{internalSettlement}/cancel    → cancel()
//
//  @layer    Controller
//  @depends  InternalSettlementService, InternalSettlement, InternalSettlementRequest, FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\InternalSettlementRequest;
use App\Models\InternalSettlement;
use App\Services\Accounting\InternalSettlementService;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InternalSettlementController extends Controller
{
    public function __construct(
        private InternalSettlementService $service,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/InternalSettlements/Index', [
            'internalSettlements' => $this->service->paginate($this->companyId($request), $request->only('search', 'partner_id')),
            'filters'             => $request->only('search', 'partner_id'),
        ]);
    }

    /**
     * Optional ?partner_id= & ?currency_id= — arrives from the
     * "Internal Settle" button on Customer Balances. Per Mahmoud: the
     * button lands on the INDEX (not straight into Create) — these
     * two, if present, just pre-filter that index list so the person
     * can immediately see (and start) the settlement for the partner
     * they clicked from.
     */
    public function create(Request $request)
    {
        return Inertia::render('Accounting/InternalSettlements/Form', [
            'internalSettlement' => null,
            'eligiblePairs'       => $this->service->eligiblePartnerCurrencyPairs($this->companyId($request)),
        ]);
    }

    public function eligiblePartners(Request $request)
    {
        return response()->json(['pairs' => $this->service->eligiblePartnerCurrencyPairs($this->companyId($request))]);
    }

    public function openInvoicesAndBills(Request $request)
    {
        $partnerId = $request->integer('partner_id');
        $currencyId = $request->integer('currency_id');

        return response()->json([
            'invoices' => $this->service->openCustomerInvoices($partnerId, $currencyId),
            'bills'    => $this->service->openSupplierBills($partnerId, $currencyId),
        ]);
    }

    public function store(InternalSettlementRequest $request)
    {
        try {
            $settlement = $this->service->record($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.internal-settlements.index')->with('success', "Internal Settlement [{$settlement->settlement_code}] recorded successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, InternalSettlement $internalSettlement)
    {
        return Inertia::render('Accounting/InternalSettlements/Form', [
            'internalSettlement' => $internalSettlement->load([
                'partner:id,code,name_en,name_ar', 'currency:id,code',
                'customerAllocations.customerInvoice:id,invoice_code,currency_id,exchange_rate,invoice_value,amount_paid,amount_outstanding',
                'supplierAllocations.supplierBill:id,bill_code,currency_id,exchange_rate,bill_value,amount_paid,amount_outstanding',
                'journalEntry.lines.chartOfAccount:id,code,name_en',
            ]),
            'eligiblePairs' => $this->service->eligiblePartnerCurrencyPairs($this->companyId($request)),
            'periodClosed'  => $this->fiscalYearService->isPeriodClosed($this->companyId($request), (string) $internalSettlement->settlement_date),
        ]);
    }

    /** Only reachable when the settlement is a draft (setToDraft() is the only way it gets there) — see InternalSettlementService::update(). */
    public function update(InternalSettlementRequest $request, InternalSettlement $internalSettlement)
    {
        try {
            $settlement = $this->service->update($this->companyId($request), $internalSettlement, $request->validated(), $request->user());
            return redirect()->route('accounting.internal-settlements.index')->with('success', "Internal Settlement [{$settlement->settlement_code}] updated and re-posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function setToDraft(Request $request, InternalSettlement $internalSettlement)
    {
        try {
            $this->service->setToDraft($internalSettlement, $request->user());
            return redirect()->route('accounting.internal-settlements.edit', $internalSettlement)->with('success', "Internal Settlement [{$internalSettlement->settlement_code}] reopened for editing.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Only reachable on a draft — the only way a settlement becomes a draft is setToDraft(), which already reversed everything real. */
    public function destroy(Request $request, InternalSettlement $internalSettlement)
    {
        try {
            $code = $internalSettlement->settlement_code;
            $this->service->delete($internalSettlement, $request->user());
            return redirect()->route('accounting.internal-settlements.index')->with('success', "Internal Settlement [{$code}] deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, InternalSettlement $internalSettlement)
    {
        try {
            $this->service->cancel($internalSettlement, $request->user());
            return back()->with('success', "Internal Settlement [{$internalSettlement->settlement_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
