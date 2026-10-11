<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PurchaseOrderLine
//
//  Mirrors SalesOrderLine — no account column (see migration 000074
//  header, PO lines are inventory-bound, not COGS-bound).
//
//  received_quantity and tolerance_pct exist now purely so the
//  future Goods Receipt Note module has somewhere to read/write
//  without a second migration — see remainingQuantity() and
//  isWithinTolerance() below, ready for GRN to call once it exists.
//
//  @layer    Model
//  @depends  BelongsToCompany, PurchaseOrder, Product, UnitOfMeasure
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'purchase_order_id', 'product_id', 'description',
        'quantity', 'uom_id', 'unit_price', 'discount_pct', 'line_total',
        'received_quantity', 'tolerance_pct', 'sort_order',
        'has_price_variance', 'price_variance_amount', 'price_variance_note',
    ];

    protected $casts = [
        'quantity'                => 'decimal:4',
        'unit_price'              => 'decimal:2',
        'discount_pct'            => 'decimal:2',
        'line_total'              => 'decimal:2',
        'received_quantity'       => 'decimal:4',
        'tolerance_pct'           => 'decimal:2',
        'has_price_variance'      => 'boolean',
        'price_variance_amount'   => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    // ── GRN helpers — now used by GoodsReceiptNoteService ───────────

    /**
     * The quantity band this line is considered "fully received"
     * within, given its tolerance_pct. e.g. quantity=1000,
     * tolerance_pct=5 → [950, 1050]. For the future GRN module.
     */
    public function toleranceBand(): array
    {
        $tolerance = bcdiv(bcmul((string) $this->quantity, (string) $this->tolerance_pct, 4), '100', 4);
        return [
            'min' => bcsub((string) $this->quantity, $tolerance, 4),
            'max' => bcadd((string) $this->quantity, $tolerance, 4),
        ];
    }

    public function isWithinTolerance(): bool
    {
        $band = $this->toleranceBand();
        return bccomp((string) $this->received_quantity, $band['min'], 4) >= 0
            && bccomp((string) $this->received_quantity, $band['max'], 4) <= 0;
    }

    /**
     * Whether received_quantity already reaches the bottom of the
     * tolerance band — used by GoodsReceiptNoteService to roll a PO
     * line's status up into the parent PO's receipt_status.
     */
    public function isFullyReceived(): bool
    {
        return bccomp((string) $this->received_quantity, $this->toleranceBand()['min'], 4) >= 0;
    }

    /**
     * How much more can still be received on this line without
     * exceeding the top of the tolerance band. Never negative.
     */
    public function maxReceivableQuantity(): string
    {
        $remaining = bcsub($this->toleranceBand()['max'], (string) $this->received_quantity, 4);
        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0';
    }

    // ── Supplier Bill price-variance helpers — see migration 000103 ─

    /**
     * Records one occurrence of a price variance against this line —
     * called by SupplierBillService on every Bill line matched back
     * to this PO line via a GRN line. Cumulative — never resets.
     */
    public function recordPriceVariance(string $amount, string $noteEntry): void
    {
        $this->update([
            'has_price_variance'    => true,
            'price_variance_amount' => bcadd((string) $this->price_variance_amount, $amount, 2),
            'price_variance_note'   => trim(($this->price_variance_note ? $this->price_variance_note . "\n" : '') . $noteEntry),
        ]);
    }
}
