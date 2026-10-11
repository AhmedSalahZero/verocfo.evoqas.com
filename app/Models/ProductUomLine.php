<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductUomLine
//
//  Alternate UOMs per product with conversion rates.
//  Max 4 per product. Exactly one is_primary per product.
//
//  conversion_rate: primary units per 1 of this UOM
//  Example: 1 Barrel = 159 Liters → conversion_rate = 159
//
//  @layer    Model
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUomLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'uom_id',
        'conversion_rate', 'is_primary',
        'use_for_purchase', 'use_for_sales',
    ];

    protected $casts = [
        'conversion_rate' => 'decimal:6',
        'is_primary'      => 'boolean',
        'use_for_purchase'=> 'boolean',
        'use_for_sales'   => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
