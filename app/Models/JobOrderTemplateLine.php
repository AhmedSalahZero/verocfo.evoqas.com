<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderTemplateLine
//
//  A material component on a JobOrderTemplate, expressed PER UNIT —
//  scaled by JobOrderService against a Job Order's own
//  quantity_to_produce when a job is created from this template. No
//  cost column — resolved at Job Order creation time from the
//  component's current company-wide WAC, same principle as
//  bom_lines.
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrderTemplate, Product, UnitOfMeasure
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrderTemplateLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'job_order_template_id', 'component_product_id',
        'quantity_per_unit', 'uom_id', 'sort_order',
    ];

    protected $casts = [
        'quantity_per_unit' => 'decimal:4',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(JobOrderTemplate::class, 'job_order_template_id');
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
