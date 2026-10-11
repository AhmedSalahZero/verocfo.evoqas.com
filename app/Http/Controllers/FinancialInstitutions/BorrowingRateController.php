<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BorrowingRateController
//
//  Manages CBE Corridor Rate history and updates.
//  When a new rate is posted:
//    1. Saves the CbeCorridorRate record
//    2. Updates all facilities where follows_corridor_rate = true
//    3. Recalculates facility_daily_balances from effective_date
//    4. Writes audit log
//
//  Routes (under fi. prefix):
//    GET    /borrowing-rate          → index (history + current rate)
//    POST   /borrowing-rate          → store (new rate entry)
//
//  Audit H6 (Sept 2026): the corridor rate is COMPANY-OWNED — the
//  page shows only this company's current rate and history, and a
//  new rate updates only this company's facilities. Posting needs
//  Treasury Operations 'create' permission (route guard).
//
//  @layer    Controller
//  @depends  BorrowingRateService, CbeCorridorRate
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\CbeCorridorRate;
use App\Services\FinancialInstitutions\BorrowingRateService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BorrowingRateController extends Controller
{
    public function __construct(private BorrowingRateService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        // Count of facilities that will be affected by next rate update
        $affectedCount = \App\Models\FinancialInstitutions\CreditFacility::where('company_id', $companyId)
            ->where('follows_corridor_rate', true)
            ->where('status', 'active')
            ->count();

        return Inertia::render('FinancialInstitutions/TreasurySettings/BorrowingRateIndex', [
            'currentRate'   => CbeCorridorRate::current($companyId),
            'history'       => CbeCorridorRate::where('company_id', $companyId)
                                ->orderByDesc('effective_date')
                                ->orderByDesc('id')
                                ->paginate(20),
            'affectedCount' => $affectedCount,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rate'           => 'required|numeric|min:0|max:100',
            'effective_date' => 'required|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId($request);
        $this->service->updateCorridorRate($companyId, $data, $request->user());

        return back()->with('success', $this->service->successMessage());
    }
}
