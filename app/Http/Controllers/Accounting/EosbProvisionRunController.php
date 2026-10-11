<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EosbProvisionRunController
//
//  HTTP layer for the monthly EOSB Provision run — create, post, Set
//  to Draft. See EosbProvisionRunService header for the full
//  accounting design. Simpler than PayrollRunController — no line-
//  item editing, no manual amounts; every figure is auto-calculated
//  from base_salary × eosb_rate_percent at creation time, and the
//  only user actions are Create, Post, and Set to Draft.
//
//  @layer    Controller
//  @depends  EosbProvisionRunService, EosbProvisionRunRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\EosbProvisionRunRequest;
use App\Models\EosbProvisionRun;
use App\Services\Accounting\EosbProvisionRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EosbProvisionRunController extends Controller
{
    public function __construct(private EosbProvisionRunService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Payroll/EosbProvisionRunIndex', [
            'runs'    => $this->service->paginate($this->companyId($request), $request->only('status')),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/Payroll/EosbProvisionRunCreate');
    }

    public function store(EosbProvisionRunRequest $request)
    {
        $data = $request->validated();

        try {
            $run = $this->service->create($this->companyId($request), (int) $data['period_year'], (int) $data['period_month'], $data['run_date'], $request->user());
            return redirect()->route('hr.eosb-provision.show', $run->id)->with('success', 'EOSB Provision Run created.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(Request $request, EosbProvisionRun $run)
    {
        return Inertia::render('Accounting/Payroll/EosbProvisionRunShow', [
            'run' => $run->load('lines.partner', 'journalEntry.lines.chartOfAccount'),
        ]);
    }

    public function post(Request $request, EosbProvisionRun $run)
    {
        try {
            $this->service->post($run, $request->user());
            return back()->with('success', 'EOSB Provision Run posted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setToDraft(Request $request, EosbProvisionRun $run)
    {
        try {
            $this->service->setToDraft($run, $request->user());
            return back()->with('success', 'EOSB Provision Run reopened to draft.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
