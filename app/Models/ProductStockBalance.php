<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductStockBalance
//
//  One row per (company_id, product_id) — the single, company-wide
//  authoritative current WAC and on-hand quantity for that product.
//  Confirmed with Mahmoud: WAC is one number per product per
//  company, never fragmented by location or by project.
//
//  This is a derived cache, maintained exclusively by
//  StockLedgerService on every stock_movements write — never
//  updated directly. Can always be rebuilt from stock_movements.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id',
        'quantity_on_hand', 'wac_unit_cost', 'wac_total_value',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'wac_unit_cost'    => 'decimal:4',
        'wac_total_value'  => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
