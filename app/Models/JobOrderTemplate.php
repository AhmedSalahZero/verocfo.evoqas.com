<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderTemplate
//
//  An OPTIONAL, reusable starting point for a Job Order's material
//  list. Deliberately simpler than BillOfMaterial — no versioning —
//  since a template here is a convenience, not a governed document.
//  Editing a template never retroactively touches a job already
//  created from it (JobOrder has its own independent JobOrderLine
//  rows, copied at creation time).
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, JobOrderTemplateLine, JobOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOrderTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'product_id', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JobOrderTemplateLine::class)->orderBy('sort_order');
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
