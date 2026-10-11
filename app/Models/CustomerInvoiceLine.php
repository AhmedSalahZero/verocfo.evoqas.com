<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerInvoiceLine
//
//  See migration 000119 for the original design rationale (mandatory
//  sales_order_line_id link, revenue_account_id copied at invoice
//  time rather than read live from the SO line).
//
//  Delivery & Revenue Recognition v1.0 (migration 000131) — a
//  physical-goods line now branches per Section 9 of that design doc:
//  accrued_cleared_quantity / deferred_created_quantity always sum to
//  this line's own quantity. price_variance_amount is the signed
//  functional-currency variance on the accrued_cleared_quantity
//  portion only (Section 4) — always 0 for a pure
//  deferred_created_quantity portion or a service line. A service
//  line (no physical delivery) leaves all three of these at their
//  defaults (0) and posts exactly as it always has.
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerInvoice, SalesOrderLine,
//            DeliveryNoteLine, Product, UnitOfMeasure, ChartOfAccount, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInvoiceLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_invoice_id', 'sales_order_line_id',
        'delivery_note_line_id', 'product_id',
        'description', 'quantity', 'uom_id', 'unit_price', 'line_subtotal',
        'vat_rate', 'vat_amount', 'line_total',
        'accrued_cleared_quantity', 'deferred_created_quantity', 'price_variance_amount',
        'accrued_cleared_functional', 'deferred_created_functional', 'fx_difference_functional', // Audit C8
        'revenue_account_id', 'cost_center_id', 'sort_order',
        'service_period_start', 'service_period_end', // Audit H18
    ];

    protected $casts = [
        'service_period_start' => 'date',
        'service_period_end'   => 'date',
        'quantity'                    => 'decimal:4',
        'unit_price'                  => 'decimal:2',
        'line_subtotal'               => 'decimal:2',
        'vat_rate'                    => 'decimal:2',
        'vat_amount'                  => 'decimal:2',
        'line_total'                  => 'decimal:2',
        'accrued_cleared_quantity'    => 'decimal:4',
        'deferred_created_quantity'   => 'decimal:4',
        'price_variance_amount'       => 'decimal:2',
    ];

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    /** Best-effort — see migration 000131 header (one invoice line's accrual clearing could in principle span more than one prior delivery). */
    public function deliveryNoteLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryNoteLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** True once this line has any physical-goods delivery involvement at all — false means it's a pure service line, unaffected by Delivery & Revenue Recognition. */
    public function isPhysicalGoodsLine(): bool
    {
        return bccomp((string) $this->accrued_cleared_quantity, '0', 4) > 0
            || bccomp((string) $this->deferred_created_quantity, '0', 4) > 0;
    }

    /** Every Credit Note line that has reversed part of this invoice line — see migration 000121. */
    public function creditNoteLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    /** Every Customer Debit Note line that has added to this invoice line — see migration 000195. */
    public function debitNoteLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerDebitNoteLine::class);
    }
}
