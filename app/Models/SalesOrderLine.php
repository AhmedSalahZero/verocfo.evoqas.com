<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderLine
//
//  Individual line items on a Sales Order.
//  Revenue account auto-assigned from product — user can override.
//  line_total = (quantity × unit_price) × (1 - discount_pct / 100)
//
//  v1.1 — added BelongsToCompany. This table was missing the standard
//    multi-tenant safety guard that every other table has — its
//    twin, PurchaseOrderLine (built later), had it correctly from
//    day one. Found during audit review; fixed before Sales Cycle
//    (Accounting Engine Phase 1) builds directly on top of this
//    table. No data or behavior changes — this only restores the
//    same protection every other company-owned table already has.
//
//  Delivery & Revenue Recognition v1.0 (migration 000128) — added
//  delivered_quantity / invoiced_quantity, two independent running
//  totals. See that migration's header for the full reconciliation
//  mechanism (net position = delivered_quantity - invoiced_quantity)
//  that DeliveryNoteService and CustomerInvoiceService both read at
//  posting time.
//
//  @layer    Model
//  @depends  BelongsToCompany, SalesOrder, Product, UnitOfMeasure,
//            ChartOfAccount, CostCenter, DeliveryNoteLine, CustomerInvoiceLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sales_order_id', 'product_id', 'description',
        'quantity', 'delivered_quantity', 'invoiced_quantity',
        'accrued_revenue_functional', 'deferred_revenue_functional', // Audit C8 — EGP held in 143 / 236
        'uom_id', 'unit_price', 'discount_pct', 'line_total',
        'revenue_account_id', 'cost_center_id', 'sort_order',
    ];

    protected $casts = [
        'quantity'             => 'decimal:4',
        'delivered_quantity'   => 'decimal:4',
        'invoiced_quantity'    => 'decimal:4',
        'accrued_revenue_functional'  => 'decimal:2',
        'deferred_revenue_functional' => 'decimal:2',
        'unit_price'           => 'decimal:2',
        'discount_pct'         => 'decimal:2',
        'line_total'           => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    /**
     * Bug fix (audit, Aug 2026): this used to be a plain, unfiltered
     * hasOne — meaning ANY reservation row for this line, including
     * an already-released one, counted as "this line has a
     * reservation." Since release() (correctly) keeps the row for
     * audit history instead of deleting it, that meant once a line's
     * reservation was released, this relation kept matching the old
     * released row forever — permanently hiding the line from
     * StockReservationService::eligibleSalesOrders(), even though it
     * genuinely had nothing active reserved anymore and should have
     * been reservable again. Renamed and scoped to STATUS_ACTIVE so
     * "has a reservation" means "has one right now," not "has ever
     * had one." At most one row can ever match, since reserve() now
     * also blocks creating a second active reservation on the same
     * line (see StockReservationService::reserve()).
     */
    public function activeReservation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\StockReservation::class)
            ->where('status', \App\Models\StockReservation::STATUS_ACTIVE);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function deliveryNoteLines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    public function customerInvoiceLines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class);
    }

    /**
     * Delivered ahead of invoicing (positive) or invoiced ahead of
     * delivery (negative) — see migration 000128 header. The single
     * number both DeliveryNoteService and CustomerInvoiceService read
     * to decide how their own new quantity splits between clearing
     * the other side's waiting account and creating a fresh one.
     */
    public function netDeliveryPosition(): string
    {
        return bcsub((string) $this->delivered_quantity, (string) $this->invoiced_quantity, 4);
    }
}
