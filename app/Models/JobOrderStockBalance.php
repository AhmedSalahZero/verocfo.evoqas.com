<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderStockBalance
//
//  One row per (company_id, job_order_id, product_id) — a single Job
//  Order's own quantity and cost for whatever it produced, kept
//  entirely separate from ProductStockBalance's company-wide pooled
//  WAC. Manufacturing Variance Closing Design v1.0, Section 6.
//
//  Confirmed with Mahmoud: every Job Order's output is genuinely
//  different from another job's output sharing the same Product code
//  (different specs, different real cost) — SAP calls the equivalent
//  concept "valuated sales order stock"; Oracle's is Project
//  Manufacturing's own lot/project cost segment. Neither pools a
//  custom job's cost into the product's general average, and neither
//  does this table.
//
//  Same "derived cache, maintained exclusively by StockLedgerService"
//  rule as ProductStockBalance — never updated directly. Can always
//  be rebuilt from stock_movements once job_order_id is added there
//  (Section 6, piece 2).
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrder, Product
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrderStockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'job_order_id', 'product_id',
        'quantity_on_hand', 'unit_cost', 'total_value',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'unit_cost'        => 'decimal:4',
        'total_value'      => 'decimal:2',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
