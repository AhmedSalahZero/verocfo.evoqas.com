<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GovernmentRemittanceController
//
//  HTTP layer for Government Remittance tracking (2026-09). Read-only
//  single page, company-wide (not per-partner, unlike Advances &
//  Settlements — see GovernmentRemittanceService header for why).
//  "Remit Tax" / "Remit Social Insurance" link out to Other Payment,
//  pre-filled with the correct debit account; the posting logic
//  itself stays owned by OtherPaymentService.
//
//  @layer    Controller
//  @depends  GovernmentRemittanceService, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\Accounting\GovernmentRemittanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernmentRemittanceController extends Controller
{
    public function __construct(
        private GovernmentRemittanceService $service,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $taxAccount = ChartOfAccount::findByCode($companyId, '226005');
        $siAccount  = ChartOfAccount::findByCode($companyId, '240');

        return Inertia::render('Accounting/GovernmentRemittance/Index', [
            'balances'          => $this->service->balances($companyId),
            'history'           => $this->service->history($companyId),
            'taxAccountId'      => $taxAccount?->id,
            'socialInsuranceAccountId' => $siAccount?->id,
        ]);
    }
}
