<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  MachineController
//
//  HTTP layer for Machines (Work Centers) — VeroCFO_Manufacturing_
//  Costing_Redesign_v1_0.md, Section 4. Create + Edit share one Vue
//  page (Rule 2). The Process/Overhead Pool/Fixed Asset pickers are
//  built the same way — active-only, plus the current record's own
//  value even if it has since gone inactive/been claimed elsewhere,
//  so an existing Machine never silently loses a value the form
//  can't display (same pattern as EmployeeProfileForm's category
//  filtering).
//
//  @layer    Controller
//  @depends  MachineService, MachineRequest, Process, OverheadPool
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\MachineRequest;
use App\Models\Machine;
use App\Models\OverheadPool;
use App\Models\Process;
use App\Services\Accounting\MachineService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MachineController extends Controller
{
    public function __construct(private MachineService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/MachineIndex', [
            'machines'  => $this->service->paginate($companyId, $request->only('search', 'process_id', 'status')),
            'processes' => Process::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
            'filters'   => $request->only('search', 'process_id', 'status'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/MachineForm', [
            'processes'      => $this->processOptions($companyId),
            'overheadPools'  => $this->overheadPoolOptions($companyId),
            'fixedAssets'    => $this->service->eligibleFixedAssets($companyId),
        ]);
    }

    public function edit(Request $request, Machine $machine)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/MachineForm', [
            'machine'        => $machine->load('process', 'fixedAsset', 'machineOverheadPools.overheadPool'),
            'processes'      => $this->processOptions($companyId, $machine),
            'overheadPools'  => $this->overheadPoolOptions($companyId, $machine),
            'fixedAssets'    => $this->service->eligibleFixedAssets($companyId, $machine->id),
        ]);
    }

    public function store(MachineRequest $request)
    {
        $machine = $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.machines.index')->with('success', "Machine [{$machine->code}] created.");
    }

    public function update(MachineRequest $request, Machine $machine)
    {
        $this->service->update($machine, $request->validated(), $request->user());

        return redirect()->route('accounting.machines.index')->with('success', 'Machine updated.');
    }

    // ── Internal ──────────────────────────────────────────────────

    private function processOptions(int $companyId, ?Machine $machine = null)
    {
        return Process::where('company_id', $companyId)
            ->where(function ($q) use ($machine) {
                $q->where('is_active', true);
                if ($machine) {
                    $q->orWhere('id', $machine->process_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function overheadPoolOptions(int $companyId, ?Machine $machine = null)
    {
        $linkedIds = $machine ? $machine->machineOverheadPools->pluck('overhead_pool_id') : collect();

        return OverheadPool::where('company_id', $companyId)
            ->where(function ($q) use ($linkedIds) {
                $q->where('is_active', true);
                if ($linkedIds->isNotEmpty()) {
                    $q->orWhereIn('id', $linkedIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
