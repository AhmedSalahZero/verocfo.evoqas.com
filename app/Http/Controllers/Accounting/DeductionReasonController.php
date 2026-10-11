<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeductionReasonController
//
//  HTTP layer for the Deduction Reason lookup list — see
//  DeductionReasonService / DeductionReason headers for the full
//  design (why `behavior` exists, why it's set once at creation).
//
//  @layer    Controller
//  @depends  DeductionReasonService, DeductionReasonRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\DeductionReasonRequest;
use App\Models\DeductionReason;
use App\Services\Accounting\DeductionReasonService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeductionReasonController extends Controller
{
    public function __construct(private DeductionReasonService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Payroll/DeductionReasonIndex', [
            'reasons' => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(DeductionReasonRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return back()->with('success', 'Deduction reason created.');
    }

    public function update(DeductionReasonRequest $request, DeductionReason $reason)
    {
        $this->service->update($reason, $request->validated(), $request->user());

        return back()->with('success', 'Deduction reason updated.');
    }

    public function toggleActive(Request $request, DeductionReason $reason)
    {
        $this->service->toggleActive($reason, $request->user());

        return back()->with('success', 'Deduction reason updated.');
    }

    public function destroy(DeductionReason $reason)
    {
        $this->service->delete($reason);

        return back()->with('success', 'Deduction reason deleted.');
    }
}
