<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  InventoryLocationService
//
//  All business logic for Inventory Location management.
//
//  createMaster():
//    Called automatically at company setup.
//    Creates the single Master Location for the company.
//    Cannot be called twice — guard against duplicate master.
//
//  create():
//    Creates a Main or Sub location.
//    Auto-generates code (LOC-001 / LOC-001-01).
//    Guards: max 2 levels, cannot create sub under sub.
//
//  createForProject():
//    Called automatically when a Project is created.
//    Creates a project_site type location linked to the project.
//    Name mirrors the project name.
//
//  update():
//    Only name_en and name_ar can change.
//    Code, type, level are immutable.
//
//  toggleActive():
//    Master locations cannot be deactivated.
//    Cannot deactivate a main with active subs.
//    Project site deactivation happens via project close.
//
//  tree():
//    Returns all locations as Main → Sub hierarchy.
//    Used by Index page.
//
//  listForDropdown():
//    Returns postable locations for transaction selectors.
//
//  @layer    Service
//  @depends  App\Models\InventoryLocation, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InventoryLocationService
{
    // ── Create Master (auto at company setup) ─────────────────────

    /**
     * Creates the Master Location for a new company.
     * Called by CompanyService after company is created.
     * Only one master per company — guarded.
     */
    public function createMaster(Company $company): InventoryLocation
    {
        $exists = InventoryLocation::where('company_id', $company->id)
            ->where('type', InventoryLocation::TYPE_MASTER)
            ->exists();

        if ($exists) {
            throw new \DomainException('Master location already exists for this company.');
        }

        return DB::transaction(function () use ($company) {
            $location = InventoryLocation::create([
                'company_id' => $company->id,
                'parent_id'  => null,
                'code'       => 'LOC-001',
                'name_en'    => 'Master Warehouse',
                'name_ar'    => 'المخزن الرئيسي',
                'type'       => InventoryLocation::TYPE_MASTER,
                'level'      => InventoryLocation::LEVEL_MAIN,
                'project_id' => null,
                'is_active'  => true,
            ]);

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => null,
                'action'         => 'create_master_location',
                'auditable_type' => InventoryLocation::class,
                'auditable_id'   => $location->id,
                'before'         => null,
                'after'          => $location->toArray(),
            ]);

            return $location;
        });
    }

    // ── Create Location ───────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): InventoryLocation
    {
        $this->validateCreate($companyId, $data);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $isMain   = empty($data['parent_id']);
            $level    = $isMain ? InventoryLocation::LEVEL_MAIN : InventoryLocation::LEVEL_SUB;
            $code     = $isMain
                ? InventoryLocation::nextMainCode($companyId)
                : InventoryLocation::nextSubCode($companyId, (int) $data['parent_id']);

            $location = InventoryLocation::create([
                'company_id' => $companyId,
                'parent_id'  => $data['parent_id'] ?? null,
                'code'       => $code,
                'name_en'    => $data['name_en'],
                'name_ar'    => $data['name_ar'] ?? null,
                'type'       => InventoryLocation::TYPE_WAREHOUSE,
                'level'      => $level,
                'project_id' => null,
                'is_active'  => true,
            ]);

            AuditLog::record($actor, 'create_location', $location, null, $location->toArray());

            return $location;
        });
    }

    // ── Create Project Site (auto when project is created) ────────

    /**
     * Auto-creates a project_site location when a Project is created.
     * Called by ProjectService (Projects stream).
     * Name mirrors the project name.
     */
    public function createForProject(
        int    $companyId,
        int    $projectId,
        string $projectNameEn,
        string $projectNameAr = null
    ): InventoryLocation {
        return DB::transaction(function () use ($companyId, $projectId, $projectNameEn, $projectNameAr) {
            $code = InventoryLocation::nextMainCode($companyId);

            $location = InventoryLocation::create([
                'company_id' => $companyId,
                'parent_id'  => null,
                'code'       => $code,
                'name_en'    => $projectNameEn . ' — Site',
                'name_ar'    => $projectNameAr ? $projectNameAr . ' — موقع المشروع' : null,
                'type'       => InventoryLocation::TYPE_PROJECT_SITE,
                'level'      => InventoryLocation::LEVEL_MAIN,
                'project_id' => $projectId,
                'is_active'  => true,
            ]);

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => null,
                'action'         => 'create_project_site_location',
                'auditable_type' => InventoryLocation::class,
                'auditable_id'   => $location->id,
                'before'         => null,
                'after'          => $location->toArray(),
            ]);

            return $location;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(InventoryLocation $location, array $data, User $actor): InventoryLocation
    {
        if ($location->isMaster()) {
            throw new \DomainException('The Master Warehouse name cannot be changed.');
        }

        return DB::transaction(function () use ($location, $data, $actor) {
            $before = $location->toArray();

            $location->update([
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'] ?? null,
            ]);

            AuditLog::record($actor, 'update_location', $location, $before, $location->fresh()->toArray());

            return $location->fresh();
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(InventoryLocation $location, User $actor): InventoryLocation
    {
        if ($location->isMaster()) {
            throw new \DomainException('The Master Warehouse cannot be deactivated.');
        }

        if ($location->isMain() && $location->is_active) {
            $activeSubs = $location->children()->where('is_active', true)->count();
            if ($activeSubs > 0) {
                throw new \DomainException(
                    "Cannot deactivate [{$location->name_en}] — it has {$activeSubs} active sub-location(s). Deactivate sub-locations first."
                );
            }
        }

        return DB::transaction(function () use ($location, $actor) {
            $before = ['is_active' => $location->is_active];
            $location->update(['is_active' => ! $location->is_active]);

            AuditLog::record($actor, 'toggle_location', $location, $before, ['is_active' => $location->is_active]);

            return $location;
        });
    }

    // ── Tree for Index page ───────────────────────────────────────

    /**
     * Returns all locations as Main → Sub tree.
     * Ordered by type (master first) then code.
     */
    public function tree(int $companyId): Collection
    {
        return InventoryLocation::with('children')
            ->main()
            ->orderByRaw("FIELD(type, 'master', 'warehouse', 'project_site')")
            ->orderBy('code')
            ->get();
    }

    // ── Dropdown for transaction forms ────────────────────────────

    /**
     * Returns all active postable locations for transaction selectors.
     * Postable = sub-locations + childless main locations.
     */
    public function listForDropdown(int $companyId): Collection
    {
        return InventoryLocation::active()
            ->postable()
            ->orderByRaw("FIELD(type, 'master', 'warehouse', 'project_site')")
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type', 'level', 'parent_id']);
    }

    // ── Main locations for parent selector ────────────────────────

    public function mainForSelector(int $companyId): Collection
    {
        return InventoryLocation::active()
            ->main()
            ->where('type', '!=', InventoryLocation::TYPE_PROJECT_SITE)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);
    }

    // ── Private ───────────────────────────────────────────────────

    private function validateCreate(int $companyId, array $data): void
    {
        if (empty($data['parent_id'])) return;

        $parent = InventoryLocation::findOrFail($data['parent_id']);

        if ($parent->level !== InventoryLocation::LEVEL_MAIN) {
            throw new \DomainException(
                'Sub-locations can only be created under a Main location. Maximum two levels allowed.'
            );
        }

        if ($parent->isMaster()) {
            // Allow sub-locations under master warehouse
            return;
        }
    }
}
