<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  CostCenterService
//
//  All business logic for Cost Center management.
//  Called exclusively by CostCenterController.
//
//  Responsibilities:
//    - Return full tree (Main → Sub) for the Index page
//    - Return flat list for dropdowns (postable only)
//    - Create Main or Sub Cost Center with auto-code
//    - Update name/ar only (code and level are immutable)
//    - Toggle active/inactive
//    - Guard: cannot deactivate a Main that has active subs
//    - Guard: cannot create a Sub under a Sub (max 2 levels)
//
//  All writes use DB::transaction(). Audit log on every write.
//
//  @layer    Service
//  @depends  App\Models\CostCenter
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CostCenterService
{
    // ── Tree for Index page ───────────────────────────────────────

    /**
     * Returns all cost centers as a flat collection sorted by code.
     * Vue renders the tree using level field for indentation.
     */
    public function tree(int $companyId): Collection
    {
        return CostCenter::with('children')
            ->main()
            ->orderBy('code')
            ->get()
            ->map(function ($main) {
                $main->children->each(fn ($sub) => $sub->setRelation('parent', $main));
                return $main;
            });
    }

    /**
     * Returns postable cost centers for transaction dropdowns.
     * Sub cost centers always included.
     * Main cost centers included only if they have no children.
     */
    public function listForDropdown(int $companyId): Collection
    {
        $all = CostCenter::active()
            ->with('children')
            ->orderBy('code')
            ->get();

        return $all->filter(fn ($cc) => $cc->isPostable())->values();
    }

    /**
     * Returns only Main Cost Centers for the parent selector
     * on the Sub Cost Center form.
     */
    public function mainCostCenters(int $companyId): Collection
    {
        return CostCenter::active()
            ->main()
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar']);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): CostCenter
    {
        $this->validateCreate($companyId, $data);

        return DB::transaction(function () use ($companyId, $data, $actor) {

            $isMain   = empty($data['parent_id']);
            $level    = $isMain ? CostCenter::LEVEL_MAIN : CostCenter::LEVEL_SUB;
            $code     = $isMain
                ? CostCenter::nextMainCode($companyId)
                : CostCenter::nextSubCode($companyId, (int) $data['parent_id']);

            $cc = CostCenter::create([
                'company_id' => $companyId,
                'code'       => $code,
                'name_en'    => $data['name_en'],
                'name_ar'    => $data['name_ar'] ?? null,
                'level'      => $level,
                'parent_id'  => $data['parent_id'] ?? null,
                'is_active'  => true,
            ]);

            AuditLog::record($actor, 'create', $cc, null, $cc->toArray());

            return $cc;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(CostCenter $cc, array $data, User $actor): CostCenter
    {
        return DB::transaction(function () use ($cc, $data, $actor) {

            $before = $cc->toArray();

            $cc->update([
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'] ?? null,
            ]);

            AuditLog::record($actor, 'update', $cc, $before, $cc->fresh()->toArray());

            return $cc->fresh();
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(CostCenter $cc, User $actor): CostCenter
    {
        // Cannot deactivate a Main that has active Sub Cost Centers
        if ($cc->isMain() && $cc->is_active) {
            $activeSubs = $cc->children()->where('is_active', true)->count();
            if ($activeSubs > 0) {
                throw new \DomainException(
                    "Cannot deactivate a Main Cost Center that has {$activeSubs} active sub cost center(s). Deactivate the sub cost centers first."
                );
            }
        }

        return DB::transaction(function () use ($cc, $actor) {

            $before = ['is_active' => $cc->is_active];
            $cc->update(['is_active' => ! $cc->is_active]);

            AuditLog::record($actor, 'toggle_active', $cc, $before, ['is_active' => $cc->is_active]);

            return $cc;
        });
    }

    // ── Private Helpers ───────────────────────────────────────────

    private function validateCreate(int $companyId, array $data): void
    {
        if (empty($data['parent_id'])) return; // Main Cost Center — no validation needed

        // Sub Cost Center — parent must be a Main (level 1)
        $parent = CostCenter::findOrFail($data['parent_id']);

        if ($parent->level !== CostCenter::LEVEL_MAIN) {
            throw new \DomainException(
                'Sub Cost Centers can only be created under a Main Cost Center. Maximum two levels allowed.'
            );
        }
    }
}
