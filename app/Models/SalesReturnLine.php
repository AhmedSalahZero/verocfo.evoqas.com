<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesReturnLine
//
//  One returned Delivery Note line (Audit H16): quantity, the original
//  delivery cost it came back at, and how it split between the part
//  not yet invoiced (accrued revenue reversed) and the part already
//  invoiced (credited or to be replaced). See migration 000305.
//
//  @layer    Model
//  @depends  BelongsToCompany, SalesReturn, DeliveryNoteLine,
//            SalesOrderLine, Product, InventoryLocation, StockMovement
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sales_return_id', 'delivery_note_line_id', 'sales_order_line_id', 'product_id', 'location_id',
        'quantity', 'unit_cost', 'total_cost', 'uninvoiced_quantity', 'invoiced_quantity',
        'accrual_reversed_functional', 'deferred_created_functional', 'stock_movement_id', 'job_order_id',
    ];

    protected $casts = [
        'quantity'                    => 'decimal:4',
        'unit_cost'                   => 'decimal:4',
        'total_cost'                  => 'decimal:2',
        'uninvoiced_quantity'         => 'decimal:4',
        'invoiced_quantity'           => 'decimal:4',
        'accrual_reversed_functional' => 'decimal:2',
        'deferred_created_functional' => 'decimal:2',
    ];

    public function salesReturn(): BelongsTo      { return $this->belongsTo(SalesReturn::class); }
    public function deliveryNoteLine(): BelongsTo { return $this->belongsTo(DeliveryNoteLine::class); }
    public function salesOrderLine(): BelongsTo   { return $this->belongsTo(SalesOrderLine::class); }
    public function product(): BelongsTo          { return $this->belongsTo(Product::class); }
    public function location(): BelongsTo         { return $this->belongsTo(InventoryLocation::class); }
}
