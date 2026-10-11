<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanFixedAsset
//
//  One fixed asset inside a plan — either a real existing FixedAsset
//  (fixed_asset_id set, snapshotted at add-time) or a planned future
//  purchase (fixed_asset_id null). Confirmed simple, two-source
//  design — see migration 000248 header. Depreciation uses the exact
//  same straight-line formula as the real FixedAsset model.
//
//  @layer    Model
//  @depends  BelongsToCompany, Plan, FixedAsset
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFixedAsset extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'plan_id',
        'fixed_asset_id',
        'name',
        'cost_basis',
        'residual_value',
        'useful_life_months',
        'accumulated_depreciation_snapshot',
        'purchase_month',
    ];

    protected $casts = [
        'cost_basis'                         => 'decimal:2',
        'residual_value'                     => 'decimal:2',
        'useful_life_months'                 => 'integer',
        'accumulated_depreciation_snapshot'  => 'decimal:2',
        'purchase_month'                     => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function isExistingAsset(): bool
    {
        return $this->fixed_asset_id !== null;
    }

    /**
     * Straight-line monthly depreciation — identical formula to the
     * real FixedAsset::monthlyDepreciationAmount(), so a planned
     * asset's numbers agree with what actually posts if/when it
     * becomes real.
     */
    public function monthlyDepreciationAmount(): string
    {
        $depreciableBase = bcsub((string) $this->cost_basis, (string) $this->residual_value, 2);

        if ($this->useful_life_months <= 0 || bccomp($depreciableBase, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv($depreciableBase, (string) $this->useful_life_months, 2);
    }

    /**
     * Net book value at plan start — cost minus whatever has already
     * depreciated (0 for a planned future purchase, since it hasn't
     * been bought yet).
     */
    public function netBookValueAtPlanStart(): string
    {
        return bcsub((string) $this->cost_basis, (string) $this->accumulated_depreciation_snapshot, 2);
    }
}
