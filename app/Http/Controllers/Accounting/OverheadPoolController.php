<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OverheadPoolController
//
//  HTTP layer for user-defined overhead pools. New sidebar entry
//  (no existing stub) — added alongside BOM/Production, gated by
//  Company::isManufacturing(). Create + Edit share one Vue page.
//
//  @layer    Controller
//  @depends  OverheadPoolService, OverheadPoolRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\OverheadPoolRequest;
use App\Models\OverheadPool;
use App\Services\Accounting\OverheadPoolService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverheadPoolController extends Controller
{
    public function __construct(private OverheadPoolService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/OverheadPoolIndex', [
            'pools'   => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters' => $request->only('search'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Accounting/Manufacturing/OverheadPoolForm');
    }

    public function edit(OverheadPool $pool)
    {
        return Inertia::render('Accounting/Manufacturing/OverheadPoolForm', ['pool' => $pool]);
    }

    public function store(OverheadPoolRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.overhead-pools.index')->with('success', 'Overhead pool created.');
    }

    public function update(OverheadPoolRequest $request, OverheadPool $pool)
    {
        $this->service->update($pool, $request->validated(), $request->user());

        return redirect()->route('accounting.overhead-pools.index')->with('success', 'Overhead pool updated.');
    }
}
