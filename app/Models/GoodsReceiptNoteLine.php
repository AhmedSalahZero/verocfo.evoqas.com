<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  GoodsReceiptNoteLine
//
//  See migration 000101 for the full design rationale
//  (purchase_order_line_id nullability, project/SO tagging,
//  functional-currency unit_cost).
//
//  @layer    Model
//  @depends  BelongsToCompany, GoodsReceiptNote, PurchaseOrderLine,
//            Product, UnitOfMeasure, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptNoteLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'goods_receipt_note_id', 'purchase_order_line_id',
        'product_id', 'description', 'quantity', 'billed_quantity', 'stock_movement_id', 'uom_id', 'unit_cost',
        'returned_unbilled_quantity', 'returned_billed_quantity', // Audit H16 — see PurchaseReturnService
        'line_total', 'project_id', 'sales_order_id', 'sort_order',
    ];

    protected $casts = [
        'quantity'        => 'decimal:4',
        'billed_quantity' => 'decimal:4',
        'unit_cost'       => 'decimal:4',
        'line_total'      => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** Every Landing Cost allocation that added to this line's inventory cost — see migration 000204. */
    public function landingCostAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LandingCostAllocation::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isAdHoc(): bool
    {
        return is_null($this->purchase_order_line_id);
    }

    public function isProjectTagged(): bool
    {
        return ! is_null($this->project_id);
    }

    // ── Supplier Bill matching helpers — see migration 000104 ──────

    /**
     * How much of this GRN line can still be billed. Unlike PO
     * receiving there is no tolerance band — a GRN line can never be
     * billed beyond exactly what it recorded as received.
     */
    public function remainingBillableQuantity(): string
    {
        // Audit H16: goods sent back before they were billed can no longer be billed.
        $remaining = bcsub(bcsub((string) $this->quantity, (string) $this->billed_quantity, 4), (string) ($this->returned_unbilled_quantity ?? '0'), 4);
        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0';
    }

    public function isFullyBilled(): bool
    {
        return bccomp((string) $this->billed_quantity, (string) $this->quantity, 4) >= 0;
    }
}
