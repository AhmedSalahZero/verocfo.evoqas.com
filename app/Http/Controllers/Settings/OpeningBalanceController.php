<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  OpeningBalanceController
//
//  HTTP layer for Opening Balances — 6 tabs on one page.
//  Zero business logic — all delegated to OpeningBalanceService.
//
//  Routes:
//    GET    /settings/opening-balances                → index()
//    POST   /settings/opening-balances/coa            → saveCoa()
//    DELETE /settings/opening-balances/coa/{id}       → deleteCoa()
//    POST   /settings/opening-balances/invoices        → saveInvoice()
//    DELETE /settings/opening-balances/invoices/{id}  → deleteInvoice()
//    POST   /settings/opening-balances/nr              → saveNr()
//    DELETE /settings/opening-balances/nr/{id}        → deleteNr()
//    POST   /settings/opening-balances/np              → saveNp()
//    DELETE /settings/opening-balances/np/{id}        → deleteNp()
//    POST   /settings/opening-balances/stock           → saveStock()
//    DELETE /settings/opening-balances/stock/{id}     → deleteStock()
//    POST   /settings/opening-balances/validate        → validate()
//    POST   /settings/opening-balances/post            → post()
//
//  v1.1 (July 2026 audit) — every save*() method now uses a
//  dedicated Form Request instead of inline $request->validate();
//  index()'s five private query helpers and inline Partner/Product/
//  ProductCategory/InventoryLocation lookups moved into
//  OpeningBalanceService::referenceData(). index() was 32 lines,
//  saveInvoice()/saveNr()/saveNp() were 21-22 — all now well under
//  the 15-line controller limit, and this file no longer queries
//  the database directly at all.
//
//  @layer    Controller
//  @depends  OpeningBalanceService, CurrencyService,
//            SaveObCoaRequest, SaveObPartnerInvoiceRequest,
//            SaveObNoteReceivableRequest, SaveObNotePayableRequest,
//            SaveObStockRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveObCoaRequest;
use App\Http\Requests\Settings\SaveObNotePayableRequest;
use App\Http\Requests\Settings\SaveObNoteReceivableRequest;
use App\Http\Requests\Settings\SaveObPartnerInvoiceRequest;
use App\Http\Requests\Settings\SaveObStockRequest;
use App\Services\CurrencyService;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpeningBalanceController extends Controller
{
    public function __construct(
        private OpeningBalanceService $service,
        private CurrencyService       $currencyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $data = array_merge(
            $this->service->loadAll($companyId),
            $this->service->referenceData($companyId),
            ['currencies' => $this->currencyService->activeForDropdown($companyId)]
        );

        return Inertia::render('Settings/OpeningBalances/Index', $data);
    }

    // ── COA Balance ───────────────────────────────────────────────

    public function saveCoa(SaveObCoaRequest $request)
    {
        try {
            $this->service->saveCoa($this->companyId($request), $request->validated(), $request->user());
            return back()->with('success', 'Account balance saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteCoa(Request $request, int $id)
    {
        try {
            $this->service->deleteCoa($this->companyId($request), $id, $request->user());
            return back()->with('success', 'Account balance removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Partner Invoices ──────────────────────────────────────────

    public function saveInvoice(SaveObPartnerInvoiceRequest $request)
    {
        try {
            $this->service->savePartnerInvoice($this->companyId($request), $request->validated(), $request->user());
            return back()->with('success', 'Invoice saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteInvoice(Request $request, int $id)
    {
        try {
            $this->service->deletePartnerInvoice($this->companyId($request), $id, $request->user());
            return back()->with('success', 'Invoice removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Notes Receivable ──────────────────────────────────────────

    public function saveNr(SaveObNoteReceivableRequest $request)
    {
        try {
            $this->service->saveNoteReceivable($this->companyId($request), $request->validated(), $request->user());
            return back()->with('success', 'Check saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteNr(Request $request, int $id)
    {
        try {
            $this->service->deleteNoteReceivable($this->companyId($request), $id, $request->user());
            return back()->with('success', 'Check removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Notes Payable ─────────────────────────────────────────────

    public function saveNp(SaveObNotePayableRequest $request)
    {
        try {
            $this->service->saveNotePayable($this->companyId($request), $request->validated(), $request->user());
            return back()->with('success', 'Check saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteNp(Request $request, int $id)
    {
        try {
            $this->service->deleteNotePayable($this->companyId($request), $id, $request->user());
            return back()->with('success', 'Check removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Inventory Stock ───────────────────────────────────────────

    public function saveStock(SaveObStockRequest $request)
    {
        try {
            $this->service->saveStock($this->companyId($request), $request->validated(), $request->user());
            return back()->with('success', 'Stock saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteStock(Request $request, int $id)
    {
        try {
            $this->service->deleteStock($this->companyId($request), $id, $request->user());
            return back()->with('success', 'Stock item removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Validate + Post ───────────────────────────────────────────

    public function validateOb(Request $request)
    {
        $result = $this->service->validate($this->companyId($request));
        // The page calls this with axios and reads the result from the response body.
        return response()->json($result);
    }

    public function post(Request $request)
    {
        try {
            $this->service->post($this->companyId($request), $request->user());
            return back()->with('success', 'Opening balances posted successfully. All data is now locked.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
