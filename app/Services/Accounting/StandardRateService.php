<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StandardRateService
//
//  Standard Rate Draft/Active/Superseded + history fix (VeroCFO_
//  Manufacturing_Flexibility_Design_v1_0.md, Section 2). Two problems
//  this replaces:
//
//    1. Setting a new rate used to overwrite the previous one in
//       place (updateOrCreate keyed on company/fiscal_year/rate_type/
//       overhead_pool_id) — no history survived a correction at all.
//       FIX: create() now always adds a new version, starting as
//       Draft. update() only works while Draft. activate() supersedes
//       whichever version currently holds Active for that same key —
//       superseded versions are NEVER deleted.
//
//    2. There was no effective-date concept on the rate itself (only
//       the separate monthly-override table had one). FIX:
//       resolveEffective() below picks whichever Active-or-Superseded
//       version's effective_from is the latest one on or before the
//       month being calculated — effectiveLaborRate()/
//       effectiveOverheadRate() now go through this instead of
//       reading a single row directly, with NO signature change, so
//       ProductionOrderService needed zero changes to its call sites.
//
//  Labor Categories (added Sept 2026 — VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 3, migration 000260) extend this with a
//  third rate_type, 'labor_category', paired with labor_category_id —
//  the exact same shape 'overhead_pool' already has with
//  overhead_pool_id. Every method below that threaded a nullable
//  $overheadPoolId through now threads a nullable $laborCategoryId
//  alongside it; the two are mutually exclusive per row (enforced in
//  create()/createAndActivate() by which rate_type was given, never
//  both set at once).
//
//  setLaborRate()/setOverheadPoolRate()/setLaborCategoryRate() are
//  kept as-is on purpose — NOT rewritten to require the Draft→Activate
//  dance. They are "create and immediately activate" convenience
//  wrappers used by automated seeding (SeedDemoManufacturingCompany
//  [V2]) where a brand-new fiscal year needs its first rate live
//  immediately with no human review step. The interactive UI
//  (StandardRateController) never calls these — it uses
//  create()/update()/activate() explicitly, giving a real person the
//  same Draft-first review workflow BOM has. Both paths funnel
//  through the same version/status/effective_from mechanics
//  underneath.
//
//  @layer    Service
//  @depends  StandardRate, StandardRateMonthlyOverride, OverheadPool, LaborCategory, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\StandardRate;
use App\Models\StandardRateMonthlyOverride;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StandardRateService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return StandardRate::with(['fiscalYear:id,name', 'overheadPool:id,name,allocation_base', 'laborCategory:id,name', 'monthlyOverrides'])
            ->where('company_id', $companyId)
            ->when($filters['fiscal_year_id'] ?? null, fn ($q, $f) => $q->where('fiscal_year_id', $f))
            ->when($filters['rate_type'] ?? null, fn ($q, $t) => $q->where('rate_type', $t))
            ->orderByDesc('fiscal_year_id')
            ->orderByRaw("FIELD(status, 'active', 'draft', 'superseded')")
            ->orderByDesc('version')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Interactive path — Draft first, always ──────────────────────
    //
    // Creates the next version for this (fiscal_year, rate_type,
    // overhead_pool / labor_category) key as a Draft — never
    // immediately live. Mirrors BillOfMaterialService::create()
    // exactly.
    public function create(int $companyId, array $data, User $actor): StandardRate
    {
        $this->assertWithinFiscalYear((int) $data['fiscal_year_id'], $data['effective_from']);

        $overheadPoolId  = $data['rate_type'] === StandardRate::TYPE_OVERHEAD_POOL ? $data['overhead_pool_id'] : null;
        $laborCategoryId = $data['rate_type'] === StandardRate::TYPE_LABOR_CATEGORY ? $data['labor_category_id'] : null;

        $rate = StandardRate::create([
            'company_id'         => $companyId,
            'fiscal_year_id'     => $data['fiscal_year_id'],
            'rate_type'          => $data['rate_type'],
            'overhead_pool_id'   => $overheadPoolId,
            'labor_category_id'  => $laborCategoryId,
            'version'            => $this->nextVersion($companyId, $data['fiscal_year_id'], $data['rate_type'], $overheadPoolId, $laborCategoryId),
            'status'             => StandardRate::STATUS_DRAFT,
            'effective_from'     => $data['effective_from'],
            'annual_rate'        => $data['annual_rate'],
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $actor->id,
        ]);

        AuditLog::record($actor, 'standard_rate.created', $rate, null, $rate->toArray());

        return $rate->fresh(['fiscalYear', 'overheadPool', 'laborCategory']);
    }

    // Only a Draft version can be edited — mirrors
    // BillOfMaterialService::update()/assertEditable() exactly. The
    // identity fields (fiscal_year_id, rate_type, overhead_pool_id,
    // labor_category_id) never change on an existing version, same
    // reasoning as BOM's product_id being immutable after creation.
    public function update(int $companyId, StandardRate $rate, array $data, User $actor): StandardRate
    {
        $this->assertEditable($rate);
        $this->assertWithinFiscalYear((int) $rate->fiscal_year_id, $data['effective_from']);

        $before = $rate->toArray();

        $rate->update([
            'effective_from' => $data['effective_from'],
            'annual_rate'    => $data['annual_rate'],
            'notes'          => $data['notes'] ?? null,
        ]);

        AuditLog::record($actor, 'standard_rate.updated', $rate, $before, $rate->fresh()->toArray());

        return $rate->fresh(['fiscalYear', 'overheadPool', 'laborCategory']);
    }

    // Makes this version the live one for its key. Whichever version
    // currently holds Active for the same (fiscal_year, rate_type,
    // overhead_pool, labor_category) becomes Superseded — never
    // deleted, still read by resolveEffective() for historical dates.
    // Mirrors BillOfMaterialService::activate() exactly.
    public function activate(int $companyId, StandardRate $rate, User $actor): StandardRate
    {
        return DB::transaction(function () use ($companyId, $rate, $actor) {
            $currentlyActive = StandardRate::where('company_id', $companyId)
                ->where('fiscal_year_id', $rate->fiscal_year_id)
                ->where('rate_type', $rate->rate_type)
                ->where('overhead_pool_id', $rate->overhead_pool_id)
                ->where('labor_category_id', $rate->labor_category_id)
                ->where('status', StandardRate::STATUS_ACTIVE)
                ->first();

            if ($currentlyActive && $currentlyActive->id === $rate->id) {
                throw new \DomainException('This version is already active.');
            }

            $this->assertEffectiveDateProgresses($rate, $currentlyActive);

            if ($currentlyActive) {
                $currentlyActive->update(['status' => StandardRate::STATUS_SUPERSEDED]);
            }

            $rate->update(['status' => StandardRate::STATUS_ACTIVE]);

            AuditLog::record($actor, 'standard_rate.activated', $rate, null, $rate->fresh()->toArray());

            return $rate->fresh();
        });
    }

    public function setMonthlyOverride(StandardRate $rate, int $year, int $month, string $overrideRate, User $actor, ?string $notes = null): StandardRateMonthlyOverride
    {
        if ($rate->status === StandardRate::STATUS_SUPERSEDED) {
            throw new \DomainException('This rate version is superseded and read-only — overrides can only be set on a Draft or Active version.');
        }

        $override = StandardRateMonthlyOverride::updateOrCreate(
            ['standard_rate_id' => $rate->id, 'year' => $year, 'month' => $month],
            ['company_id' => $rate->company_id, 'rate' => $overrideRate, 'notes' => $notes]
        );

        // Fix: audit_log.action is capped at 30 chars — the original
        // 34-char string was a latent bug, never yet triggered live.
        AuditLog::record($actor, 'standard_rate.override_set', $rate, null, $override->toArray());

        return $override;
    }

    // ── Resolution ──────────────────────────────────────────────────
    //
    // The single source of truth for "which version of this rate
    // applies on this date" (VeroCFO_Manufacturing_Flexibility_
    // Design_v1_0.md, Section 2.2). Mirrors
    // BillOfMaterialService::resolveEffective() exactly: searches
    // Active AND Superseded versions (never Draft) and returns
    // whichever one's effective_from is the latest that still falls
    // on or before $date. $laborCategoryId added Section 3 — always
    // null for 'labor'/'overhead_pool' lookups, exactly mirroring how
    // $overheadPoolId is always null for 'labor'/'labor_category'
    // lookups.

    public function resolveEffective(int $companyId, int $fiscalYearId, string $rateType, ?int $overheadPoolId, ?int $laborCategoryId, string $date): ?StandardRate
    {
        return StandardRate::where('company_id', $companyId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('rate_type', $rateType)
            ->where('overhead_pool_id', $overheadPoolId)
            ->where('labor_category_id', $laborCategoryId)
            ->everEffective()
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    // ── Entry points other services should use — SAME SIGNATURE as
    // before this fix, so ProductionOrderService needed zero call-site
    // changes. $year/$month are turned into the first-of-month date
    // resolveEffective() needs; the resolved version's own
    // effectiveRate() then still applies any monthly override exactly
    // as before. ──

    public function effectiveLaborRate(int $companyId, int $fiscalYearId, int $year, int $month): string
    {
        $date = Carbon::create($year, $month, 1)->toDateString();
        $rate = $this->resolveEffective($companyId, $fiscalYearId, StandardRate::TYPE_LABOR, null, null, $date);

        return $rate ? $rate->effectiveRate($year, $month) : '0.0000';
    }

    public function effectiveOverheadRate(int $companyId, int $fiscalYearId, int $overheadPoolId, int $year, int $month): string
    {
        $date = Carbon::create($year, $month, 1)->toDateString();
        $rate = $this->resolveEffective($companyId, $fiscalYearId, StandardRate::TYPE_OVERHEAD_POOL, $overheadPoolId, null, $date);

        return $rate ? $rate->effectiveRate($year, $month) : '0.0000';
    }

    // New entry point (Section 3) — mirrors effectiveOverheadRate()
    // exactly. Not called by anything yet: BOM's routing still only
    // has a single blanket labor-hours field (Section 5 replaces
    // that with per-operation Labor Category assignment, at which
    // point ProductionOrderService starts calling this).
    public function effectiveLaborCategoryRate(int $companyId, int $fiscalYearId, int $laborCategoryId, int $year, int $month): string
    {
        $date = Carbon::create($year, $month, 1)->toDateString();
        $rate = $this->resolveEffective($companyId, $fiscalYearId, StandardRate::TYPE_LABOR_CATEGORY, null, $laborCategoryId, $date);

        return $rate ? $rate->effectiveRate($year, $month) : '0.0000';
    }

    // ── Automated / seeding path — create AND activate in one call.
    // See class header for why these are kept and who should (and
    // should not) call them. ──

    public function setLaborRate(int $companyId, int $fiscalYearId, string $annualRate, User $actor, ?string $notes = null): StandardRate
    {
        return $this->createAndActivate($companyId, $fiscalYearId, StandardRate::TYPE_LABOR, null, null, $annualRate, $actor, $notes);
    }

    public function setOverheadPoolRate(int $companyId, int $fiscalYearId, int $overheadPoolId, string $annualRate, User $actor, ?string $notes = null): StandardRate
    {
        return $this->createAndActivate($companyId, $fiscalYearId, StandardRate::TYPE_OVERHEAD_POOL, $overheadPoolId, null, $annualRate, $actor, $notes);
    }

    public function setLaborCategoryRate(int $companyId, int $fiscalYearId, int $laborCategoryId, string $annualRate, User $actor, ?string $notes = null): StandardRate
    {
        return $this->createAndActivate($companyId, $fiscalYearId, StandardRate::TYPE_LABOR_CATEGORY, null, $laborCategoryId, $annualRate, $actor, $notes);
    }

    // ── Internal ──────────────────────────────────────────────────

    // A rate that starts after its fiscal year has ended can never be picked up by any
    // order in that year, so it would silently never apply — refuse it up front.
    private function assertWithinFiscalYear(int $fiscalYearId, $effectiveFrom): void
    {
        $year = \App\Models\FiscalYear::find($fiscalYearId);
        if ($year && $year->end_date && \Illuminate\Support\Carbon::parse($effectiveFrom)->greaterThan($year->end_date)) {
            throw new \DomainException('The effective date ('.\Illuminate\Support\Carbon::parse($effectiveFrom)->format('d-m-Y').') is after the end of '.$year->name.' ('.$year->end_date->format('d-m-Y').') — the rate would never apply. Pick a date inside the fiscal year.');
        }
    }

    private function assertEditable(StandardRate $rate): void
    {
        if (! $rate->isEditable()) {
            throw new \DomainException(
                'This Standard Rate is '.$rate->status.' and can no longer be edited — create a new version instead.'
            );
        }
    }

    // Prevents activating a version whose effective_from would be on
    // or before the version it's replacing — mirrors
    // BillOfMaterialService::assertEffectiveDateProgresses() exactly,
    // for the same reason: it would make resolveEffective()'s
    // "latest date wins" logic ambiguous.
    private function assertEffectiveDateProgresses(StandardRate $rate, ?StandardRate $currentlyActive): void
    {
        if (! $currentlyActive) {
            return;
        }

        if ($rate->effective_from->lessThanOrEqualTo($currentlyActive->effective_from)) {
            throw new \DomainException(
                'This version\'s effective date ('.$rate->effective_from->format('d-m-Y').') must be after '.
                'the currently active version\'s effective date ('.$currentlyActive->effective_from->format('d-m-Y').').'
            );
        }
    }

    private function nextVersion(int $companyId, int $fiscalYearId, string $rateType, ?int $overheadPoolId, ?int $laborCategoryId = null): int
    {
        return (int) StandardRate::where('company_id', $companyId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('rate_type', $rateType)
            ->where('overhead_pool_id', $overheadPoolId)
            ->where('labor_category_id', $laborCategoryId)
            ->max('version') + 1;
    }

    private function createAndActivate(int $companyId, int $fiscalYearId, string $rateType, ?int $overheadPoolId, ?int $laborCategoryId, string $annualRate, User $actor, ?string $notes): StandardRate
    {
        return DB::transaction(function () use ($companyId, $fiscalYearId, $rateType, $overheadPoolId, $laborCategoryId, $annualRate, $actor, $notes) {
            $currentlyActive = StandardRate::where('company_id', $companyId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('rate_type', $rateType)
                ->where('overhead_pool_id', $overheadPoolId)
                ->where('labor_category_id', $laborCategoryId)
                ->where('status', StandardRate::STATUS_ACTIVE)
                ->first();

            // Seeding always sets the rate as of the start of the fiscal
            // year it belongs to when there's no prior active version —
            // matching the old "one rate for the whole fiscal year"
            // behavior exactly for this automated path. If a rate is
            // already active for this key, the new one takes over from
            // today instead, same as any other correction would.
            $effectiveFrom = $currentlyActive
                ? now()->toDateString()
                : FiscalYear::findOrFail($fiscalYearId)->start_date->toDateString();

            $rate = StandardRate::create([
                'company_id'        => $companyId,
                'fiscal_year_id'    => $fiscalYearId,
                'rate_type'         => $rateType,
                'overhead_pool_id'  => $overheadPoolId,
                'labor_category_id' => $laborCategoryId,
                'version'           => $this->nextVersion($companyId, $fiscalYearId, $rateType, $overheadPoolId, $laborCategoryId),
                'status'            => StandardRate::STATUS_DRAFT,
                'effective_from'    => $effectiveFrom,
                'annual_rate'       => $annualRate,
                'notes'             => $notes,
                'created_by'        => $actor->id,
            ]);

            if ($currentlyActive) {
                $currentlyActive->update(['status' => StandardRate::STATUS_SUPERSEDED]);
            }

            $rate->update(['status' => StandardRate::STATUS_ACTIVE]);

            AuditLog::record($actor, 'standard_rate.set', $rate, null, $rate->fresh()->toArray());

            return $rate->fresh();
        });
    }
}
