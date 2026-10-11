<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeductionReasonService
//
//  CRUD for the Deduction Reason lookup list — see DeductionReason /
//  migration 000227 headers for why `behavior` exists and why it's
//  set once at creation, never re-decided per payroll line.
//
//  A reason already used on a posted Payroll Run's line items can
//  still be deactivated (is_active = false, hides it from future
//  dropdowns) but never deleted — same "never remove financial
//  history" posture as everywhere else in this app.
//
//  @layer    Service
//  @depends  DeductionReason, PayrollRunLineItem, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\DeductionReason;
use App\Models\PayrollRunLineItem;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeductionReasonService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return DeductionReason::where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('name_en', 'like', "%{$v}%"))
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();
    }

    public function listForDropdown(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return DeductionReason::where('company_id', $companyId)->active()
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'behavior']);
    }

    public function create(int $companyId, array $data, User $actor): DeductionReason
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $reason = DeductionReason::create([
                'company_id' => $companyId,
                'name_en'    => $data['name_en'],
                'name_ar'    => $data['name_ar'] ?? null,
                'behavior'   => $data['behavior'],
                'is_active'  => true,
                'created_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'deduction_reason.created', $reason, null, $reason->toArray());

            return $reason;
        });
    }

    public function update(DeductionReason $reason, array $data, User $actor): DeductionReason
    {
        return DB::transaction(function () use ($reason, $data, $actor) {
            $before = $reason->toArray();

            $reason->update([
                'name_en'  => $data['name_en'],
                'name_ar'  => $data['name_ar'] ?? null,
                'behavior' => $data['behavior'],
            ]);

            AuditLog::record($actor, 'deduction_reason.updated', $reason, $before, $reason->fresh()->toArray());

            return $reason->fresh();
        });
    }

    public function toggleActive(DeductionReason $reason, User $actor): DeductionReason
    {
        $before = $reason->toArray();
        $reason->update(['is_active' => ! $reason->is_active]);
        AuditLog::record($actor, 'deduction_reason.toggled', $reason, $before, $reason->fresh()->toArray());

        return $reason->fresh();
    }

    public function delete(DeductionReason $reason): void
    {
        $used = PayrollRunLineItem::where('deduction_reason_id', $reason->id)->exists();

        if ($used) {
            throw new \DomainException('This reason has been used on a Payroll Run — deactivate it instead of deleting.');
        }

        $reason->delete();
    }
}
