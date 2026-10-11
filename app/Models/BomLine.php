<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  BomLine
//
//  A material component of a BillOfMaterial. quantity_per_batch is
//  scaled by ProductionOrderService against the parent BOM's
//  output_quantity. No cost column — material cost is resolved at
//  Production Order time from the component's current company-wide
//  WAC (ProductStockBalance), never stored or frozen on the line
//  itself. See migration 000086 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, BillOfMaterial, Product, UnitOfMeasure
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bill_of_material_id', 'component_product_id',
        'quantity_per_batch', 'uom_id', 'sort_order',
    ];

    protected $casts = [
        'quantity_per_batch' => 'decimal:4',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
