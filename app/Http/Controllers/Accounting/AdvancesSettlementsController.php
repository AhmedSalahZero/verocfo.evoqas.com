<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AdvancesSettlementsController
//
//  HTTP layer for the Advances & Settlements dashboard (2026-09).
//  Read-only — this module never posts anything itself. "Give New
//  Advance" links out to Other Payment, "Record Settlement" links
//  out to Other Collection, both pre-filled with this employee and
//  partner_type=employee via query string; the actual posting logic
//  stays owned by those two existing, already-audited services
//  rather than being duplicated here.
//
//  @layer    Controller
//  @depends  AdvancesSettlementsService, Partner
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Accounting\AdvancesSettlementsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdvancesSettlementsController extends Controller
{
    public function __construct(
        private AdvancesSettlementsService $service,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/AdvancesSettlements/Index', [
            'rows' => $this->service->paginate($this->companyId($request)),
        ]);
    }

    public function show(Request $request, Partner $partner)
    {
        $companyId = $this->companyId($request);

        // Explicit check rather than relying solely on CompanyScope —
        // route model binding resolves before ActiveCompany middleware
        // runs (see CompanyScope's own header note), and this page
        // shows real financial balances.
        abort_if($partner->company_id !== $companyId, 404);

        return Inertia::render('Accounting/AdvancesSettlements/Show', [
            'partner'  => $partner->only('id', 'code', 'name_en', 'name_ar'),
            'balances' => $this->service->balancesForPartner($companyId, $partner->id),
            'history'  => $this->service->history($companyId, $partner->id),
        ]);
    }
}
