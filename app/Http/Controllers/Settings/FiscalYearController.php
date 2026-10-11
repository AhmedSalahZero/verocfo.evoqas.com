<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  FiscalYearController
//
//  HTTP layer for Fiscal Year and Accounting Period settings.
//  Zero business logic — all delegated to FiscalYearService.
//
//  Routes:
//    GET    /settings/fiscal-years                          → index()
//    POST   /settings/fiscal-years/next                     → createNext()
//    POST   /settings/fiscal-years/{fiscalYear}/close       → close()
//    POST   /settings/fiscal-years/periods/{period}/close   → closePeriod()
//    POST   /settings/fiscal-years/periods/{period}/reopen  → reopenPeriod()
//
//  createNext() is restricted to Company Admin — enforced here
//  before delegating to service.
//
//  @layer    Controller
//  @depends  App\Services\FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FiscalYearController extends Controller
{
    public function __construct(private FiscalYearService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/FiscalYears/Index', [
            'fiscal_years' => $this->service->list($this->companyId($request)),
        ]);
    }

    // ── Create Next Fiscal Year ───────────────────────────────────

    public function createNext(Request $request)
    {
        // Company Admin only — Rule 26.2
        if (! $request->user()->isCompanyAdmin()) {
            abort(403, 'Only Company Admins can create fiscal years.');
        }

        try {
            $fy = $this->service->createNext(
                $this->companyId($request),
                $request->user()
            );
            return back()->with('success', "Fiscal year [{$fy->name}] created with {$fy->periods->count()} periods.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Close Fiscal Year ─────────────────────────────────────────

    public function close(Request $request, FiscalYear $fiscalYear)
    {
        try {
            $this->service->closeFiscalYear($fiscalYear, $request->user());
            return back()->with('success', "Fiscal year [{$fiscalYear->name}] closed successfully.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Close Period ──────────────────────────────────────────────

    public function closePeriod(Request $request, AccountingPeriod $period)
    {
        try {
            $this->service->closePeriod($period, $request->user());
            return back()->with('success', "Period [{$period->name}] closed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Reopen Period ─────────────────────────────────────────────

    public function reopenPeriod(Request $request, AccountingPeriod $period)
    {
        // Audit H4: a written reason is mandatory (admin check in the service).
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        try {
            $this->service->reopenPeriod($period, $request->user(), $data['reason']);
            return back()->with('success', "Period [{$period->name}] reopened.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
