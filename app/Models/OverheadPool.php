<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  OverheadPool
//
//  A company-defined overhead pool (e.g. "Factory Rent," "Machine
//  Depreciation"). Confirmed with Mahmoud: any number of pools, each
//  picks exactly one of 3 allocation bases. The pool's rate lives in
//  StandardRate, not here — this is just identity + allocation base.
//
//  Genuinely shared between Mass Production and Job Order — the one
//  deliberate point of reuse between the two otherwise-separate
//  structures (see JobOrderCostEntry's header). Both
//  ProductionOrderCostEntry and JobOrderCostEntry point back here.
//
//  machineLinks() (added Sept 2026 — Manufacturing Costing Redesign,
//  Section 4.4, Option A): which Machine(s) this pool's running cost
//  is fed by — a genuine many-to-many, see MachineOverheadPool /
//  migration 000263. A pool can be shared across several machines
//  (e.g. one "Factory Electricity" pool) or dedicated to one.
//
//  applied_account_id (Section 7, Sept 2026 — Applied vs Actual
//  variance closing): this pool's OWN dedicated GL sub-account under
//  238 Manufacturing Overhead Absorbed, auto-created the moment the
//  pool itself is created (OverheadPoolService::create() /
//  ensureAppliedAccount()) — same auto-creation pattern already used
//  for Machine/Lease sub-accounts. Every recordOverheadCost()/
//  logPieceOperationCost() posting for THIS pool credits THIS
//  account specifically, never a shared company-wide 238 balance —
//  what makes per-pool variance reconciliation possible at all. See
//  migration 000269's header for why 238 was promoted from a single
//  postable leaf to a non-postable parent.
//
//  @layer    Model
//  @depends  BelongsToCompany, StandardRate, ProductionOrderCostEntry,
//            JobOrderCostEntry, MachineOverheadPool, ChartOfAccount, Accrual
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OverheadPool extends Model
{
    use BelongsToCompany;

    // ── Allocation Base Constants — the fixed vocabulary; pool count is not fixed ──
    const BASE_LABOR_HOURS     = 'labor_hours';
    const BASE_MACHINE_HOURS   = 'machine_hours';
    const BASE_PRODUCTION_UNITS = 'production_units';

    protected $fillable = [
        'company_id', 'name', 'allocation_base', 'is_active', 'applied_account_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function standardRates(): HasMany
    {
        return $this->hasMany(StandardRate::class);
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProductionOrderCostEntry::class);
    }

    public function jobOrderCostEntries(): HasMany
    {
        return $this->hasMany(JobOrderCostEntry::class);
    }

    /** Link rows to the Machine(s) that feed this pool — see MachineOverheadPool. */
    public function machineLinks(): HasMany
    {
        return $this->hasMany(MachineOverheadPool::class);
    }

    public function appliedAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'applied_account_id');
    }

    /** Real Accrual rows tagged to this pool — Section 7's Actual side, see Accrual::overheadPool(). */
    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }
}
