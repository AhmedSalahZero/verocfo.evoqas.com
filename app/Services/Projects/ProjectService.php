<?php

namespace App\Services\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectService
//
//  All business logic for Project / Contract master data.
//  On create: auto-generates contract code + creates project_site location.
//  On close: validates all SOs are closed, deactivates project_site location.
//  On status change: blocks invalid transitions.
//
//  Fix log:
//    - InventoryLocation::create() was crashing with "Field 'code'
//      doesn't have a default value". Three bugs in that one call:
//        1. 'location_type' is not a real column — fillable field
//           is 'type'. Was being silently dropped by mass assignment.
//        2. 'created_by' does not exist on inventory_locations at all
//           — also silently dropped, removed for clarity.
//        3. 'code' is required (unique per company, no DB default)
//           and was never supplied. Added InventoryLocation::
//           nextProjectSiteCode() and call it here.
//      Also added project_id, which was never being set — the
//      project_site location was never actually linked back to the
//      project that created it.
//    - generateContractCode(): letter order corrected to
//      {project letters}{customer letters} per agreed format
//      (was customer-first, project-second).
//
//  @layer    Service
//  @depends  Project, SalesOrder, InventoryLocation, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Projects\ProjectHasOpenSOsException;
use App\Exceptions\Projects\ProjectSiteHasStockException;
use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProductLocationStock;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Project::with(['partner:id,name_en,name_ar', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('name_en', 'like', "%{$s}%")
                    ->orWhere('name_ar', 'like', "%{$s}%")
                    ->orWhere('contract_code', 'like', "%{$s}%")
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $data['company_id']    = $companyId;
            $data['created_by']    = $actor->id;
            $data['contract_code'] = $this->generateContractCode(
                $companyId,
                $data['partner_id'],
                $data['name_en']
            );

            $project = Project::create($data);

            // Auto-create project_site Inventory Location
            $location = InventoryLocation::create([
                'company_id' => $companyId,
                'code'       => InventoryLocation::nextProjectSiteCode($companyId),
                'name_en'    => $project->name_en . ' — Site',
                'name_ar'    => ($project->name_ar ?? $project->name_en) . ' — موقع',
                'type'       => InventoryLocation::TYPE_PROJECT_SITE,
                'level'      => InventoryLocation::LEVEL_MAIN,
                'project_id' => $project->id,
                'is_active'  => true,
            ]);

            $project->update(['project_site_location_id' => $location->id]);

            AuditLog::record($actor, 'project.created', $project, null, $project->toArray());

            return $project->fresh(['partner', 'currency']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $before = $project->toArray();
            $data['updated_by'] = $actor->id;

            $project->update($data);

            AuditLog::record($actor, 'project.updated', $project, $before, $project->fresh()->toArray());

            return $project->fresh(['partner', 'currency']);
        });
    }

    // ── Status transitions ────────────────────────────────────────

    public function changeStatus(int $companyId, Project $project, string $newStatus, User $actor, bool $confirmStockLeft = false): Project
    {
        // Audit L-19: only real transitions. Closed and cancelled are final.
        // Asking for the status the project already has changes nothing.
        $allowed = [
            'active'    => ['on_hold', 'closed', 'cancelled'],
            'on_hold'   => ['active', 'closed', 'cancelled'],
            'closed'    => [],
            'cancelled' => [],
        ];
        if ($newStatus === $project->status) {
            return $project;
        }
        if (! in_array($newStatus, $allowed[$project->status] ?? [], true)) {
            throw new \DomainException("A project that is {$project->status} cannot be changed to {$newStatus}.");
        }

        return DB::transaction(function () use ($companyId, $project, $newStatus, $actor, $confirmStockLeft) {
            $before = $project->toArray();

            if ($newStatus === 'closed') {
                // Rule: all SOs must be closed before project can close
                if (! $project->isCloseable()) {
                    throw new ProjectHasOpenSOsException(
                        'Cannot close project. There are open Sales Orders linked to this project. Close all Sales Orders first.'
                    );
                }

                // Deactivate project_site location — hard-warns if stock
                // remains, unless the user already confirmed past that
                // warning (see deactivateProjectSite()).
                if ($project->project_site_location_id) {
                    $this->deactivateProjectSite($project, $actor, $confirmStockLeft);
                }
            }

            $project->update(['status' => $newStatus, 'updated_by' => $actor->id]);

            AuditLog::record($actor, 'project.status_changed', $project, $before, $project->fresh()->toArray());

            return $project->fresh();
        });
    }

    // ── Collateral lookup — for OD Against Contract/SO ────────────

    public function eligibleCollateral(int $companyId, string $afterDate): array
    {
        return Project::eligibleCollateral($afterDate)
            ->get(['id', 'contract_code', 'name_en', 'end_date'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'type'         => 'project',
                'label'        => $p->contract_code . ' — ' . $p->name_en,
                'end_date'     => $p->end_date->format('Y-m-d'),
            ])
            ->toArray();
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * Contract code format (agreed): {project letters}{customer letters}/{year}/{month}/{seq}
     * e.g. CC/CBNBSC/2026/06/0001 where CB = "Core Banking" (project), NSC = customer initials
     *
     * First 2 letters from project name, first 2 letters from customer name,
     * year, month, 4-digit auto-numbering reset monthly per company.
     */
    private function generateContractCode(int $companyId, int $partnerId, string $projectName): string
    {
        $partner  = Partner::find($partnerId);
        $pr       = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $projectName), 0, 2));
        $cu       = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $partner?->name_en ?? 'XX'), 0, 2));
        $year     = now()->format('Y');
        $month    = now()->format('m');

        // Audit L-7: one atomic counter per company per month (the letters
        // are only decoration, so they do not split the numbering).
        $n = \App\Support\DocumentNumber::nextNumber(
            $companyId, Project::class, 'contract_code', 'CC', $year.$month, 'CC/%/'.$year.'/'.$month.'/%',
            fn (int $n) => "CC/{$pr}{$cu}/{$year}/{$month}/".str_pad((string) $n, 4, '0', STR_PAD_LEFT)
        );

        return "CC/{$pr}{$cu}/{$year}/{$month}/".str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Fix (July 2026 audit — #6 / #9, applied): this was previously a
     * literal `// TODO` — the location was deactivated unconditionally,
     * with no stock check at all, across two audits. Built now using
     * ProductLocationStock (the same table GRN and Stock Transfers
     * already use for "how much of this product is physically here").
     *
     * Confirm-to-proceed gate, matching Project Module doc §4.3/§12
     * Rule 3 ("hard warning ... user must confirm"), not a permanent
     * block: $confirmStockLeft = false (default) → stock found throws
     * ProjectSiteHasStockException, listing what's still there, and
     * nothing is deactivated. $confirmStockLeft = true (the user
     * explicitly confirmed, e.g. clicked "close anyway" after seeing
     * the warning) → proceeds and deactivates regardless.
     */
    private function deactivateProjectSite(Project $project, User $actor, bool $confirmStockLeft = false): void
    {
        $location = InventoryLocation::find($project->project_site_location_id);
        if (! $location || ! $location->is_active) {
            return;
        }

        if (! $confirmStockLeft) {
            $remaining = ProductLocationStock::where('inventory_location_id', $location->id)
                ->where('quantity_on_hand', '>', 0)
                ->count();

            if ($remaining > 0) {
                throw new ProjectSiteHasStockException(
                    "This project's site still has stock in {$remaining} " .
                    ($remaining === 1 ? 'item' : 'items') . " — closing the project will " .
                    'deactivate its site location while that stock is still recorded there. ' .
                    'Confirm you want to close anyway, or move/consume the stock first.'
                );
            }
        }

        $location->update(['is_active' => false]);
    }
}