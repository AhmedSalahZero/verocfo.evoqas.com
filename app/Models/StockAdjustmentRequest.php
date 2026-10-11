<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  StockAdjustmentRequest
//
//  A manual stock adjustment waiting for a second person (Audit M-18,
//  same maker-checker idea as ManualJournalRequest). Holds exactly what
//  will be posted; nothing touches stock or the ledger while it is
//  pending. Approval runs StockAdjustmentService::adjust() with these
//  values and links the resulting stock movement back here.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, InventoryLocation, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentRequest extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'product_id', 'inventory_location_id', 'job_order_id', 'direction',
        'quantity', 'unit_cost', 'movement_date', 'reason_code', 'count_reference', 'notes',
        'status', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity'      => 'decimal:4',
        'unit_cost'     => 'decimal:4',
        'movement_date' => 'date',
        'decided_at'    => 'datetime',
    ];

    public function product(): BelongsTo           { return $this->belongsTo(Product::class); }
    public function inventoryLocation(): BelongsTo { return $this->belongsTo(InventoryLocation::class); }
    public function requestedBy(): BelongsTo       { return $this->belongsTo(User::class, 'requested_by'); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
