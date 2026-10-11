<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RevenueScheduleController
//
//  Accounting › Revenue Schedules (Audit H18): service contracts
//  invoiced up front, their month-by-month release, and the monthly
//  "release revenue up to" run. Thin layer over RevenueScheduleService.
//
//  @layer    Controller
//  @depends  RevenueScheduleService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Services\Accounting\RevenueScheduleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RevenueScheduleController extends Controller
{
    public function __construct(private RevenueScheduleService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $date = (string) $request->query('date', now()->subMonthNoOverflow()->endOfMonth()->toDateString());

        return Inertia::render('Accounting/RevenueSchedules/Index', [
            'schedules' => $this->service->paginate($companyId, $request->only('status')),
            'due'       => $this->service->dueSummary($companyId, $date),
            'filters'   => $request->only('status') + ['date' => $date],
        ]);
    }

    public function release(Request $request)
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        try {
            $result = $this->service->releaseUpTo($this->companyId($request), $data['date'], $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$result['count']} month(s) of revenue released — {$result['amount']} moved from Deferred Revenue (236) to revenue.");
    }
}
