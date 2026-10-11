<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductLocationStock
//
//  One row per (company_id, product_id, inventory_location_id) —
//  QUANTITY ONLY. Deliberately carries no cost column — WAC lives
//  exclusively on ProductStockBalance (company-wide). This table
//  answers "how much of this product is physically in this specific
//  warehouse/site" without implying that location has its own price.
//
//  sum(ProductLocationStock.quantity_on_hand) for a product across
//  all its locations must always equal
//  ProductStockBalance.quantity_on_hand for that product — both
//  maintained together, in the same transaction, by
//  StockLedgerService on every movement.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, InventoryLocation
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLocationStock extends Model
{
    use BelongsToCompany;

    protected $table = 'product_location_stock';

    protected $fillable = [
        'company_id', 'product_id', 'inventory_location_id', 'quantity_on_hand',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }
}
