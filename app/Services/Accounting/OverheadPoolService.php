<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OverheadPoolService
//
//  Plain CRUD for user-defined overhead pools. No versioning, no
//  status lifecycle — a pool is either active or not. Its rate lives
//  in StandardRate (see StandardRateService), not here.
//
//  Section 7 (Sept 2026, Applied vs Actual variance closing): create()
//  now also auto-creates this pool's own dedicated Level 4 GL
//  sub-account under 238 Manufacturing Overhead Absorbed — see
//  migration 000269's header for why. ensureAppliedAccount() is the
//  defensive fallback for any pool created before this fix (this
//  demo/test data included) that has no applied_account_id yet —
//  called from ProductionOrderService/JobOrderService right before
//  they'd otherwise need it, so a pre-existing pool is fixed up
//  lazily on next use rather than needing a separate backfill command.
//
//  @layer    Service
//  @depends  OverheadPool, ChartOfAccountService, ChartOfAccount, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\OverheadPool;
use App\Models\User;
use App\Services\ChartOfAccountService;
use Illuminate\Pagination\LengthAwarePaginator;

class OverheadPoolService
{
    public function __construct(private ChartOfAccountService $chartOfAccounts) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return OverheadPool::when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): OverheadPool
    {
        $data['company_id'] = $companyId;
        $pool = OverheadPool::create($data);

        $this->ensureAppliedAccount($pool, $actor);

        AuditLog::record($actor, 'overhead_pool.created', $pool, null, $pool->toArray());

        return $pool->fresh();
    }

    public function update(OverheadPool $pool, array $data, User $actor): OverheadPool
    {
        $before = $pool->toArray();
        $pool->update($data);

        AuditLog::record($actor, 'overhead_pool.updated', $pool, $before, $pool->fresh()->toArray());

        return $pool->fresh();
    }

    /**
     * Guarantees $pool->applied_account_id is set, creating the
     * sub-account under 238 if it isn't (either a brand-new pool, or
     * one created before this fix existed). Safe to call every time —
     * a pool that already has one is a no-op.
     */
    public function ensureAppliedAccount(OverheadPool $pool, User $actor): ChartOfAccount
    {
        if ($pool->applied_account_id) {
            return $pool->appliedAccount;
        }

        $parent = ChartOfAccount::withoutGlobalScopes()->where('code', '238')->whereNull('company_id')->firstOrFail();

        $account = $this->chartOfAccounts->create($pool->company_id, [
            'parent_id'      => $parent->id,
            'use_auto_code'  => true,
            'name_en'        => $pool->name.' — Applied',
            'name_ar'        => $pool->name.' — محمّل',
        ], $actor);

        $pool->update(['applied_account_id' => $account->id]);

        return $account;
    }
}
