<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProcessController
//
//  HTTP layer for user-defined manufacturing processes. New sidebar
//  entry, added alongside Machines, gated by Company::isManufacturing().
//  Create + Edit share one Vue page. Mirrors LaborCategoryController
//  exactly.
//
//  @layer    Controller
//  @depends  ProcessService, ProcessRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ProcessRequest;
use App\Models\Process;
use App\Services\Accounting\ProcessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProcessController extends Controller
{
    public function __construct(private ProcessService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/ProcessIndex', [
            'processes' => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters'   => $request->only('search'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Accounting/Manufacturing/ProcessForm');
    }

    public function edit(Process $process)
    {
        return Inertia::render('Accounting/Manufacturing/ProcessForm', ['process' => $process]);
    }

    public function store(ProcessRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.processes.index')->with('success', 'Process created.');
    }

    public function update(ProcessRequest $request, Process $process)
    {
        $this->service->update($process, $request->validated(), $request->user());

        return redirect()->route('accounting.processes.index')->with('success', 'Process updated.');
    }
}
