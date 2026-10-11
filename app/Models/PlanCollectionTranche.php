<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanCollectionTranche
//
//  One collection tranche for a plan_product's revenue — see
//  migration 000241 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, PlanProduct
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanCollectionTranche extends Model
{
    use BelongsToCompany;

    public const MARKET_LOCAL  = 'local';
    public const MARKET_EXPORT = 'export';

    protected $fillable = [
        'company_id',
        'plan_product_id',
        'market',
        'pct',
        'days_after_sale',
    ];

    protected $casts = [
        'pct' => 'decimal:4',
    ];

    public function planProduct(): BelongsTo
    {
        return $this->belongsTo(PlanProduct::class);
    }
}
