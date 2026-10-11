<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderLine
//
//  The job's OWN material list — either copied from a
//  JobOrderTemplate's lines at creation (then fully independent) or
//  entered ad-hoc when no template is used. quantity is the TOTAL
//  for this job — already scaled if it came from a template's
//  per-unit figure. No cost column — resolved at consumption time
//  from current WAC.
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrder, Product, UnitOfMeasure
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrderLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'job_order_id', 'component_product_id', 'quantity', 'uom_id', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
