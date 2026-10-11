<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PurchaseReturnLine
//
//  One returned Goods Receipt Note line (Audit H16): quantity, the
//  original receipt cost, what actually left inventory, and how it
//  split between the unbilled and the already-billed part. See
//  migration 000305.
//
//  @layer    Model
//  @depends  BelongsToCompany, PurchaseReturn, GoodsReceiptNoteLine,
//            Product, InventoryLocation, StockMovement
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'purchase_return_id', 'goods_receipt_note_line_id', 'product_id', 'location_id',
        'quantity', 'unit_cost', 'total_cost', 'stock_value', 'unbilled_quantity', 'billed_quantity', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity'          => 'decimal:4',
        'unit_cost'         => 'decimal:4',
        'total_cost'        => 'decimal:2',
        'stock_value'       => 'decimal:2',
        'unbilled_quantity' => 'decimal:4',
        'billed_quantity'   => 'decimal:4',
    ];

    public function purchaseReturn(): BelongsTo       { return $this->belongsTo(PurchaseReturn::class); }
    public function goodsReceiptNoteLine(): BelongsTo { return $this->belongsTo(GoodsReceiptNoteLine::class); }
    public function product(): BelongsTo              { return $this->belongsTo(Product::class); }
    public function location(): BelongsTo             { return $this->belongsTo(InventoryLocation::class); }
}
