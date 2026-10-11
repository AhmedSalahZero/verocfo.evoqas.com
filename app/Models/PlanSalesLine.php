<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanSalesLine
//
//  One month's price × volume for a plan_product — see migration
//  000242 header. period_month is 0-based (0 = the plan's first
//  month).
//
//  @layer    Model
//  @depends  BelongsToCompany, PlanProduct
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanSalesLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'plan_product_id',
        'period_month',
        'price',
        'volume',
        'local_pct',
        'export_pct',
    ];

    protected $casts = [
        'price'      => 'decimal:2',
        'volume'     => 'decimal:4',
        'local_pct'  => 'decimal:4',
        'export_pct' => 'decimal:4',
    ];

    public function planProduct(): BelongsTo
    {
        return $this->belongsTo(PlanProduct::class);
    }

    /** Gross revenue for this month, before VAT — price × volume. bcmath. */
    public function grossRevenue(): string
    {
        return bcmul((string) $this->price, (string) $this->volume, 2);
    }
}
