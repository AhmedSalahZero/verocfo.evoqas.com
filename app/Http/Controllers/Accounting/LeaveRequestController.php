<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveRequestController
//
//  HTTP layer for Leave Management. Thin by design: every rule lives
//  in LeaveRequestService (see that class's header for the four that
//  matter), and this only translates requests into service calls and
//  service exceptions into a message next to the form.
//
//  The transition endpoints are separate verbs rather than a single
//  "set status" endpoint, deliberately: submit, approve, reject and
//  cancel are four different actions with four different permission
//  meanings, and collapsing them into one route would make them
//  indistinguishable to the module permission middleware.
//
//  DomainException is caught and returned as a validation error on
//  the field that caused it where one can be identified, so an
//  overlap or an exhausted entitlement reads like a form error
//  rather than a crash — the same treatment every other posting
//  screen in this application gives a refused business rule.
//
//  @layer    Controller
//  @depends  LeaveRequestService, LeaveTypeService, LeaveRequestRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\GuardsTenantBoundModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\Partner;
use App\Services\Accounting\LeaveRequestService;
use App\Services\Accounting\LeaveTypeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveRequestController extends Controller
{
    use GuardsTenantBoundModels;

    public function __construct(
        private LeaveRequestService $service,
        private LeaveTypeService $leaveTypes,
    ) {
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Leave/Index', [
            'requests'   => $this->service->paginate($companyId, $request->only(
                'search', 'status', 'partner_id', 'leave_type_id', 'date_from', 'date_to'
            )),
            'employees'  => $this->employees($companyId),
            'leaveTypes' => $this->leaveTypes->activeFor($companyId),
            'statuses'   => LeaveRequest::ALL_STATUSES,
            'filters'    => $request->only(
                'search', 'status', 'partner_id', 'leave_type_id', 'date_from', 'date_to'
            ),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Leave/Form', [
            'leaveRequest' => null,
            'employees'    => $this->employees($companyId),
            'leaveTypes'   => $this->leaveTypes->activeFor($companyId),
        ]);
    }

    public function edit(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Leave/Form', [
            'leaveRequest' => $leave->load(['partner:id,name_en', 'leaveType:id,name_en']),
            'employees'    => $this->employees($companyId),
            'leaveTypes'   => $this->leaveTypes->activeFor($companyId),
        ]);
    }

    public function store(LeaveRequestRequest $request)
    {
        try {
            $this->service->create($this->companyId($request), $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['from_date' => $e->getMessage()]);
        }

        return redirect()->route('hr.leave.index')->with('success', 'Leave request created.');
    }

    public function update(LeaveRequestRequest $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        try {
            $this->service->update($leave, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['from_date' => $e->getMessage()]);
        }

        return redirect()->route('hr.leave.index')->with('success', 'Leave request updated.');
    }

    public function destroy(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        try {
            $this->service->delete($leave, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave request deleted.');
    }

    // ── Transitions ───────────────────────────────────────────────

    public function submit(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        return $this->transition(
            fn () => $this->service->submit($leave, $request->user()),
            'Leave request submitted for approval.'
        );
    }

    public function approve(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        return $this->transition(
            fn () => $this->service->approve($leave, $request->user()),
            'Leave request approved.'
        );
    }

    public function reject(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        $reason = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ])['rejection_reason'] ?? null;

        return $this->transition(
            fn () => $this->service->reject($leave, $request->user(), $reason),
            'Leave request rejected.'
        );
    }

    public function cancel(Request $request, LeaveRequest $leave)
    {
        $this->guardTenant($request, $leave);

        return $this->transition(
            fn () => $this->service->cancel($leave, $request->user()),
            'Leave request cancelled.'
        );
    }

    // ── Balances ──────────────────────────────────────────────────

    /**
     * One employee's standing across every active leave type. Read by
     * the request form as the employee is chosen, so the person
     * raising the request sees what is left before they submit rather
     * than after it is refused.
     */
    public function balances(Request $request, Partner $partner)
    {
        $this->guardTenant($request, $partner);

        return response()->json([
            'balances' => $this->service->balanceSummary(
                $this->companyId($request),
                $partner->id,
                $request->query('on_date')
            ),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────

    private function transition(callable $action, string $message)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }

    /** Employee-type partners only — leave belongs to staff. */
    private function employees(int $companyId)
    {
        return Partner::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereJsonContains('types', Partner::TYPE_EMPLOYEE)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }
}
