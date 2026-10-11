<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveTypeService
//
//  CRUD for the per-company leave type list — the same shape
//  DeductionReasonService has, because it is the same kind of thing:
//  a small lookup list a company configures once and then picks from.
//
//  The one rule with teeth is delete(): a leave type that any request
//  has ever used cannot be removed, only deactivated. Deleting it
//  would orphan the history of everyone who took that leave, and the
//  restrictOnDelete foreign key in migration 000280 would refuse
//  anyway — this turns that database error into a sentence someone
//  can act on.
//
//  is_paid is settable on update, deliberately. A company that set a
//  type up wrongly must be able to correct it. Note the consequence:
//  unpaidLeaveDaysFor() reads is_paid live from the type rather than
//  from a per-request snapshot, so flipping it re-characterises leave
//  that has already been approved. That is the same known rough edge
//  DeductionReasonRequest documents for `behavior`, and it is
//  recorded here for the same reason — worth a snapshot field if it
//  ever proves to matter in practice.
//
//  @layer    Service
//  @depends  LeaveType, LeaveRequest, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\DeductionReason;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeaveTypeService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LeaveType::with('deductionReason:id,name_en')
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($q) => $q
                    ->where('name_en', 'like', "%{$s}%")
                    ->orWhere('name_ar', 'like', "%{$s}%")
                )
            )
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();
    }

    /** Active types only — what a leave request form offers. */
    public function activeFor(int $companyId)
    {
        return LeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'is_paid', 'annual_entitlement_days']);
    }

    public function create(int $companyId, array $data, User $actor): LeaveType
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $type = LeaveType::create([
                'company_id'              => $companyId,
                'name_en'                 => $data['name_en'],
                'name_ar'                 => $data['name_ar'] ?? null,
                'is_paid'                 => $data['is_paid'] ?? true,
                'annual_entitlement_days' => $data['annual_entitlement_days'] ?? null,
                'skip_non_working_days'   => (bool) ($data['skip_non_working_days'] ?? false),
                'senior_entitlement_days' => $data['senior_entitlement_days'] ?? null,
                'senior_after_years'      => $data['senior_after_years'] ?? 10,
                'senior_age'              => $data['senior_age'] ?? 50,
                'carry_forward_max_days'  => $data['carry_forward_max_days'] ?? null,
                'deduction_reason_id'     => $this->resolveDeductionReasonId($companyId, $data),
                'is_active'               => true,
                'created_by'              => $actor->id,
            ]);

            AuditLog::record($actor, 'leave_type.created', $type, null, $type->toArray());

            return $type->fresh('deductionReason');
        });
    }

    public function update(LeaveType $type, array $data, User $actor): LeaveType
    {
        return DB::transaction(function () use ($type, $data, $actor) {
            $before = $type->toArray();

            $type->update([
                'name_en'                 => $data['name_en'],
                'name_ar'                 => $data['name_ar'] ?? null,
                'is_paid'                 => $data['is_paid'] ?? true,
                'annual_entitlement_days' => $data['annual_entitlement_days'] ?? null,
                'skip_non_working_days'   => (bool) ($data['skip_non_working_days'] ?? false),
                'senior_entitlement_days' => $data['senior_entitlement_days'] ?? null,
                'senior_after_years'      => $data['senior_after_years'] ?? 10,
                'senior_age'              => $data['senior_age'] ?? 50,
                'carry_forward_max_days'  => $data['carry_forward_max_days'] ?? null,
                'deduction_reason_id'     => $this->resolveDeductionReasonId($type->company_id, $data),
            ]);

            AuditLog::record($actor, 'leave_type.updated', $type, $before, $type->fresh()->toArray());

            return $type->fresh('deductionReason');
        });
    }

    public function toggleActive(LeaveType $type, User $actor): LeaveType
    {
        $before = $type->toArray();

        $type->update(['is_active' => ! $type->is_active]);

        AuditLog::record($actor, 'leave_type.toggled_active', $type, $before, $type->fresh()->toArray());

        return $type->fresh();
    }

    /**
     * Only ever possible for a type nobody has used. Anything else is
     * a deactivation — see the class header.
     */
    public function delete(LeaveType $type, User $actor): void
    {
        $used = LeaveRequest::withoutGlobalScopes()
            ->where('leave_type_id', $type->id)
            ->count();

        if ($used > 0) {
            throw new \DomainException(
                "\"{$type->name_en}\" has been used on {$used} leave request(s) and cannot be deleted. "
                .'Deactivate it instead — existing requests keep their history and it stops appearing on new ones.'
            );
        }

        AuditLog::record($actor, 'leave_type.deleted', $type, $type->toArray(), null);

        $type->delete();
    }

    /**
     * A deduction reason only makes sense on an UNPAID type — it is
     * the hand-off Payroll uses to raise the earned reduction. Silently
     * dropping it on a paid type keeps the data honest rather than
     * storing a link nothing will ever read.
     */
    private function resolveDeductionReasonId(int $companyId, array $data): ?int
    {
        $isPaid = $data['is_paid'] ?? true;

        if ($isPaid) {
            return null;
        }

        $reasonId = $data['deduction_reason_id'] ?? null;

        if (! $reasonId) {
            return null;
        }

        $exists = DeductionReason::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $reasonId)
            ->exists();

        if (! $exists) {
            throw new \DomainException('The selected deduction reason does not belong to this company.');
        }

        return (int) $reasonId;
    }
}
