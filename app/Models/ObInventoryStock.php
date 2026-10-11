<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  ObInventoryStock — Inventory Opening Stock
//  unit_cost = total_value / quantity (auto-calculated)
//  One record per product per location (unique constraint).
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObInventoryStock extends Model
{
    use BelongsToCompany;

    protected $table = 'ob_inventory_stock';

    protected $fillable = [
        'company_id', 'product_id', 'location_id', 'uom_id',
        'quantity', 'total_value', 'unit_cost', 'notes',
    ];

    protected $casts = [
        'quantity'    => 'decimal:4',
        'total_value' => 'decimal:4',
        'unit_cost'   => 'decimal:6',
    ];

    // Auto-calculate unit_cost on save
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->quantity && bccomp((string)$model->quantity, '0', 4) > 0) {
                $model->unit_cost = bcdiv(
                    (string) $model->total_value,
                    (string) $model->quantity,
                    6
                );
            } else {
                $model->unit_cost = '0.000000';
            }
        });
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function location(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
    public function uom(): BelongsTo { return $this->belongsTo(UnitOfMeasure::class, 'uom_id'); }
}
