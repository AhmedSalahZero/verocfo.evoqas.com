<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  StockReservation
//
//  General stock reservation — see migration for the full design
//  rationale (company-wide, not location-specific; one row per Sales
//  Order Line; released manually today). Written exclusively through
//  StockReservationService.
//
//  @layer    Model
//  @depends  BelongsToCompany, SalesOrder, SalesOrderLine, Product, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_ACTIVE   = 'active';
    const STATUS_RELEASED = 'released';

    protected $fillable = [
        'company_id', 'sales_order_id', 'sales_order_line_id', 'product_id',
        'quantity', 'status', 'reserved_by', 'reserved_at',
        'released_by', 'released_at', 'notes',
    ];

    protected $casts = [
        'quantity'     => 'decimal:4',
        'reserved_at'  => 'datetime',
        'released_at'  => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
