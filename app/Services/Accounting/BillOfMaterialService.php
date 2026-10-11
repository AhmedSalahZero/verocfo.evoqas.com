<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BillOfMaterialService
//
//  BOM Draft/Active/Superseded fix (VeroCFO_Manufacturing_
//  Flexibility_Design_v1_0.md, Section 1). Two problems this
//  replaces:
//
//    1. There was no Draft state — a BOM could be edited at any
//       time, even after Production Orders had used it, with no way
//       to catch a mistake before it went live. FIX: create() now
//       always starts a new version as Draft. update() only works
//       while Draft (assertEditable()) — once Active or Superseded,
//       the only way to change materials is a brand new Draft
//       version, activated when ready.
//
//    2. effective_date existed on the table but was never actually
//       used to decide which BOM applies — ProductionOrderService
//       just let the user manually pick from whichever BOMs
//       happened to be flagged active. FIX: resolveEffective() below
//       is now the single source of truth ProductionOrderService
//       calls, picking whichever Active-or-Superseded version's
//       effective_date is the latest one on or before the date being
//       calculated — so a Production Order dated before a later
//       switchover automatically resolves to the version that was
//       actually in effect then, and nothing needs to be picked by
//       hand.
//
//  Only one version per product may be status = Active at a time —
//  activate() supersedes whichever version currently holds that
//  status. Superseded versions are NEVER deleted or hidden from
//  resolution — see resolveEffective().
//
//  Operations (Section 5 — VeroCFO_Manufacturing_Costing_Redesign_v1_
//  0.md) are synced the same way material lines already are —
//  syncOperations() below mirrors syncLines() exactly (delete-then-
//  recreate on every save, sequence set from array position). Only
//  a Draft version's operations can change, same assertEditable()
//  gate as everything else on this model.
//
//  @layer    Service
//  @depends  BillOfMaterial, BomLine, BomOperation, Product, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\BillOfMaterial;
use App\Models\BomLine;
use App\Models\BomOperation;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BillOfMaterialService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return BillOfMaterial::with('product:id,code,name_en,name_ar')
            ->where('company_id', $companyId)
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->whereHas('product', fn ($q) => $q
                    ->where('code', 'like', "%{$s}%")
                    ->orWhere('name_en', 'like', "%{$s}%")
                )
            )
            ->orderByRaw("FIELD(status, 'active', 'draft', 'superseded')")
            ->orderByDesc('version')
            ->paginate(20)
            ->withQueryString();
    }

    // Always creates a new version as DRAFT — never immediately live.
    // The previous behavior (auto-activating on create) is exactly
    // what removed any chance to catch a mistake before it governed
    // real Production Orders; see class header.
    public function create(int $companyId, array $data, User $actor): BillOfMaterial
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            $operations = $data['operations'] ?? [];
            unset($data['lines'], $data['operations'], $data['status']);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $data['version']    = $this->nextVersion($companyId, $data['product_id']);
            $data['status']     = BillOfMaterial::STATUS_DRAFT;

            $bom = BillOfMaterial::create($data);

            $this->syncLines($bom, $companyId, $lines);
            $this->syncOperations($bom, $companyId, $operations);

            AuditLog::record($actor, 'bill_of_material.created', $bom, null, $bom->toArray());

            return $bom->fresh(['product', 'lines.componentProduct', 'lines.uom', 'operations.process', 'operations.defaultMachine', 'operations.laborCategory']);
        });
    }

    // Only a Draft version can be edited — once Active or Superseded,
    // materials are locked (see class header). This is what actually
    // closes the gap Mahmoud reported: pick the wrong component while
    // still Draft, and it's a normal edit; discover it after
    // activation, and the fix is a new Draft version, never an
    // in-place rewrite of something a Production Order may already
    // have used.
    public function update(int $companyId, BillOfMaterial $bom, array $data, User $actor): BillOfMaterial
    {
        $this->assertEditable($bom);

        return DB::transaction(function () use ($companyId, $bom, $data, $actor) {
            $before = $bom->toArray();

            $lines = $data['lines'] ?? [];
            $operations = $data['operations'] ?? [];
            unset($data['lines'], $data['operations'], $data['product_id'], $data['version'], $data['status']);

            $bom->update($data);
            $this->syncLines($bom, $companyId, $lines);
            $this->syncOperations($bom, $companyId, $operations);

            AuditLog::record($actor, 'bill_of_material.updated', $bom, $before, $bom->fresh()->toArray());

            return $bom->fresh(['product', 'lines.componentProduct', 'lines.uom', 'operations.process', 'operations.defaultMachine', 'operations.laborCategory']);
        });
    }

    // Makes this version the live one. Whichever version currently
    // holds Active for this product becomes Superseded (never
    // deleted — resolveEffective() keeps reading it for historical
    // dates). Guards against an effective_date that would make the
    // switchover ambiguous — see assertEffectiveDateProgresses().
    public function activate(int $companyId, BillOfMaterial $bom, User $actor): BillOfMaterial
    {
        return DB::transaction(function () use ($companyId, $bom, $actor) {
            $currentlyActive = BillOfMaterial::where('company_id', $companyId)
                ->where('product_id', $bom->product_id)
                ->where('status', BillOfMaterial::STATUS_ACTIVE)
                ->first();

            if ($currentlyActive && $currentlyActive->id === $bom->id) {
                throw new \DomainException('This version is already active.');
            }

            $this->assertEffectiveDateProgresses($bom, $currentlyActive);

            if ($currentlyActive) {
                $currentlyActive->update(['status' => BillOfMaterial::STATUS_SUPERSEDED]);
            }

            $bom->update(['status' => BillOfMaterial::STATUS_ACTIVE]);

            AuditLog::record($actor, 'bill_of_material.activated', $bom, null, $bom->fresh()->toArray());

            return $bom->fresh();
        });
    }

    // ── Resolution — the single source of truth for "which BOM
    // applies on this date" (VeroCFO_Manufacturing_Flexibility_
    // Design_v1_0.md, Section 1.2). Called by ProductionOrderService
    // instead of trusting a manually-picked BOM id. Searches Active
    // AND Superseded versions (never Draft — it has never taken
    // effect) and returns whichever one's effective_date is the
    // latest that still falls on or before $date — this is what lets
    // a backdated Production Order correctly resolve to the version
    // that actually governed production on that date, even after a
    // newer version has since taken over.

    public function resolveEffective(int $companyId, int $productId, string $date): ?BillOfMaterial
    {
        return BillOfMaterial::with([
                'lines.componentProduct',
                // Section 5 — needed by ProductionOrderService's
                // standard labor/overhead calculation, which reads
                // straight through to each operation's Labor Category
                // and its default Machine's linked Overhead Pools.
                'operations.laborCategory',
                'operations.defaultMachine.machineOverheadPools.overheadPool',
            ])
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->everEffective()
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('version')
            ->first();
    }

    // ── Internal ──────────────────────────────────────────────────

    private function assertEditable(BillOfMaterial $bom): void
    {
        if (! $bom->isEditable()) {
            throw new \DomainException(
                'This Bill of Materials is '.$bom->status.' and can no longer be edited — create a new version instead.'
            );
        }
    }

    // Prevents activating a version whose effective_date would be on
    // or before the version it's replacing — that would make it
    // ambiguous which one actually governed the overlapping window,
    // and would silently break resolveEffective()'s "latest date
    // wins" logic. The fix is always to pick a later effective_date.
    private function assertEffectiveDateProgresses(BillOfMaterial $bom, ?BillOfMaterial $currentlyActive): void
    {
        if (! $currentlyActive) {
            return;
        }

        if ($bom->effective_date->lessThanOrEqualTo($currentlyActive->effective_date)) {
            throw new \DomainException(
                'This version\'s effective date ('.$bom->effective_date->format('d-m-Y').') must be after '.
                'the currently active version\'s effective date ('.$currentlyActive->effective_date->format('d-m-Y').').'
            );
        }
    }

    private function nextVersion(int $companyId, int $productId): int
    {
        return (int) BillOfMaterial::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->max('version') + 1;
    }

    private function syncLines(BillOfMaterial $bom, int $companyId, array $lines): void
    {
        $bom->lines()->delete();

        foreach ($lines as $i => $line) {
            BomLine::create([
                'company_id'            => $companyId,
                'bill_of_material_id'   => $bom->id,
                'component_product_id'  => $line['component_product_id'],
                'quantity_per_batch'    => $line['quantity_per_batch'],
                'uom_id'                => $line['uom_id'] ?? null,
                'sort_order'            => $i,
            ]);
        }
    }

    // Delete-then-recreate, same idempotent "wipe and reapply" pattern
    // as syncLines() above — sequence is set from array position, not
    // user input, mirroring BomLine's sort_order exactly. Operations
    // are optional: a BOM with none simply carries zero standard
    // labor/overhead cost (e.g. a pure-assembly product a company
    // isn't tracking labor for yet) — same flexibility the old flat
    // fields had.
    private function syncOperations(BillOfMaterial $bom, int $companyId, array $operations): void
    {
        BomOperation::where('bill_of_material_id', $bom->id)->delete();

        foreach ($operations as $i => $op) {
            BomOperation::create([
                'company_id'              => $companyId,
                'bill_of_material_id'     => $bom->id,
                'sequence'                => $i,
                'process_id'              => $op['process_id'],
                'default_machine_id'      => $op['default_machine_id'],
                'labor_category_id'       => $op['labor_category_id'],
                'standard_labor_hours'    => $op['standard_labor_hours'] ?? 0,
                'standard_machine_hours'  => $op['standard_machine_hours'] ?? 0,
            ]);
        }
    }
}
