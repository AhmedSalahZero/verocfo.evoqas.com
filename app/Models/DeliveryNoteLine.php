<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  DeliveryNoteLine
//
//  See migration 000130 for the full design rationale (mandatory
//  sales_order_line_id, WAC capture from the real StockLedgerService
//  call, the deferred/accrued split).
//
//  @layer    Model
//  @depends  BelongsToCompany, DeliveryNote, SalesOrderLine, Product,
//            UnitOfMeasure, StockMovement
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'delivery_note_id', 'sales_order_line_id', 'location_id', 'product_id',
        'description', 'quantity', 'uom_id',
        'unit_price', 'line_revenue_amount',
        'wac_unit_cost', 'wac_total_value', 'stock_movement_id',
        'deferred_cleared_quantity', 'accrued_created_quantity', 'sort_order',
        'accrued_created_functional', 'deferred_cleared_functional', // Audit C8
        'returned_quantity', // Audit H16 — see SalesReturnService
    ];

    protected $casts = [
        'quantity'                    => 'decimal:4',
        'unit_price'                  => 'decimal:2',
        'line_revenue_amount'         => 'decimal:2',
        'wac_unit_cost'               => 'decimal:4',
        'wac_total_value'             => 'decimal:2',
        'deferred_cleared_quantity'   => 'decimal:4',
        'accrued_created_quantity'    => 'decimal:4',
    ];

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /** Moved here from the DeliveryNote header (July 2026, per Mahmoud) — one delivery can ship different lines from different physical locations. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Models\InventoryLocation::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /** How much of this line's quantity is still available for a Customer Invoice to clear (Accrued Revenue side). */
    public function remainingAccruedQuantity(): string
    {
        // A future partial-invoicing-of-one-delivery-line feature
        // (deliberately deferred — design doc Section 5) would need
        // its own running "invoiced_from_this_line_quantity" column
        // here. For now this always equals the full
        // accrued_created_quantity, since that clears in one shot on
        // whichever invoice first references it.
        return (string) $this->accrued_created_quantity;
    }
}
