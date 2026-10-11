<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveTypeController
//
//  HTTP layer for the per-company leave type list. Same shape as
//  DeductionReasonController, because it is the same kind of screen:
//  a small lookup list edited inline, no separate create page.
//
//  Deletion is offered but usually refused — LeaveTypeService only
//  allows it for a type nothing has used, and turns the refusal into
//  a sentence telling the user to deactivate instead. That message is
//  surfaced here rather than swallowed.
//
//  @layer    Controller
//  @depends  LeaveTypeService, LeaveTypeRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\GuardsTenantBoundModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LeaveTypeRequest;
use App\Models\DeductionReason;
use App\Models\LeaveType;
use App\Services\Accounting\LeaveTypeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveTypeController extends Controller
{
    use GuardsTenantBoundModels;

    public function __construct(private LeaveTypeService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Leave/Types', [
            'types' => $this->service->paginate($companyId, $request->only('search')),
            // Only offered on unpaid types — it is the hand-off to
            // Payroll's earned_reduction mechanism.
            'deductionReasons' => DeductionReason::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('behavior', DeductionReason::BEHAVIOR_EARNED_REDUCTION)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'name_en']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(LeaveTypeRequest $request)
    {
        try {
            $this->service->create($this->companyId($request), $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name_en' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave type created.');
    }

    public function update(LeaveTypeRequest $request, LeaveType $type)
    {
        $this->guardTenant($request, $type);

        try {
            $this->service->update($type, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name_en' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave type updated.');
    }

    public function toggleActive(Request $request, LeaveType $type)
    {
        $this->guardTenant($request, $type);

        $this->service->toggleActive($type, $request->user());

        return back()->with('success', 'Leave type updated.');
    }

    public function destroy(Request $request, LeaveType $type)
    {
        $this->guardTenant($request, $type);

        try {
            $this->service->delete($type, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['name_en' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave type deleted.');
    }
}
