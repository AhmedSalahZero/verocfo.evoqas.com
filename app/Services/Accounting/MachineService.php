<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  MachineService
//
//  CRUD for Machines (Work Centers) — VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 4. Auto-codes on create (Machine::
//  nextCode(), same M-00001 pattern as Product's P-00001), validates
//  the optional Fixed Asset link doesn't collide with another
//  machine, and owns every write to machine_overhead_pools (Section
//  4.4, Option A — many-to-many) via syncOverheadPools().
//
//  syncOverheadPools() is a delete-then-create-many pass, not a
//  diff/patch — the same "wipe and reapply" idempotent pattern
//  already used elsewhere in this app (Facility Settlement Engine
//  resettlement, Building Bible Rule 34.4) rather than trying to
//  compute which links were added/removed. Simple, always correct,
//  and cheap at this scale (a handful of pools per machine).
//
//  @layer    Service
//  @depends  Machine, MachineOverheadPool, FixedAsset, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\FixedAsset;
use App\Models\Machine;
use App\Models\MachineOverheadPool;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MachineService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Machine::with(['process:id,name', 'fixedAsset:id,asset_code,name', 'machineOverheadPools.overheadPool:id,name'])
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($q2) use ($s) {
                $q2->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%");
            }))
            ->when($filters['process_id'] ?? null, fn ($q, $v) => $q->where('process_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): Machine
    {
        $this->assertFixedAssetAvailable($companyId, $data['fixed_asset_id'] ?? null, null);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $machine = Machine::create([
                'company_id'      => $companyId,
                'code'            => Machine::nextCode($companyId),
                'name'            => $data['name'],
                'process_id'      => $data['process_id'],
                'fixed_asset_id'  => $data['fixed_asset_id'] ?? null,
                'status'          => $data['status'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $actor->id,
            ]);

            $this->syncOverheadPools($machine, $data['overhead_pool_ids']);

            AuditLog::record($actor, 'machine.created', $machine, null, $machine->toArray());

            return $machine->fresh(['process', 'fixedAsset', 'machineOverheadPools.overheadPool']);
        });
    }

    public function update(Machine $machine, array $data, User $actor): Machine
    {
        $this->assertFixedAssetAvailable($machine->company_id, $data['fixed_asset_id'] ?? null, $machine->id);

        return DB::transaction(function () use ($machine, $data, $actor) {
            $before = $machine->toArray();

            $machine->update([
                'name'            => $data['name'],
                'process_id'      => $data['process_id'],
                'fixed_asset_id'  => $data['fixed_asset_id'] ?? null,
                'status'          => $data['status'],
                'notes'           => $data['notes'] ?? null,
                'updated_by'      => $actor->id,
            ]);

            $this->syncOverheadPools($machine, $data['overhead_pool_ids']);

            AuditLog::record($actor, 'machine.updated', $machine, $before, $machine->fresh()->toArray());

            return $machine->fresh(['process', 'fixedAsset', 'machineOverheadPools.overheadPool']);
        });
    }

    /**
     * Fixed Assets available to link to a NEW or DIFFERENT machine —
     * every one belonging to this company that isn't already linked
     * to some OTHER machine. $excludingMachineId lets an edit screen
     * still offer the asset this exact machine already holds.
     */
    public function eligibleFixedAssets(int $companyId, ?int $excludingMachineId = null): \Illuminate\Support\Collection
    {
        return FixedAsset::where('company_id', $companyId)
            ->whereDoesntHave('machine', function ($q) use ($excludingMachineId) {
                if ($excludingMachineId) {
                    $q->where('id', '!=', $excludingMachineId);
                }
            })
            ->orderBy('asset_code')
            ->get(['id', 'asset_code', 'name']);
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * A friendly DomainException before the DB's own unique
     * constraint on machines.fixed_asset_id would otherwise reject
     * this — same posture as every other "this would collide" check
     * in this app (e.g. EmployeePayrollProfileService::create()).
     */
    private function assertFixedAssetAvailable(int $companyId, ?int $fixedAssetId, ?int $excludingMachineId): void
    {
        if (! $fixedAssetId) {
            return;
        }

        $conflict = Machine::where('company_id', $companyId)
            ->where('fixed_asset_id', $fixedAssetId)
            ->when($excludingMachineId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($conflict) {
            throw new \DomainException('This Fixed Asset is already linked to a different Machine.');
        }
    }

    private function syncOverheadPools(Machine $machine, array $overheadPoolIds): void
    {
        MachineOverheadPool::where('machine_id', $machine->id)->delete();

        foreach (array_unique($overheadPoolIds) as $poolId) {
            MachineOverheadPool::create([
                'company_id'        => $machine->company_id,
                'machine_id'        => $machine->id,
                'overhead_pool_id'  => $poolId,
            ]);
        }
    }
}
