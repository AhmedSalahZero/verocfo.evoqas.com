<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  StandardRate
//
//  One shared mechanism for the annual labor rate (blanket, single
//  company-wide — rate_type 'labor'), every overhead pool's annual
//  rate ('overhead_pool', paired with overhead_pool_id), and every
//  labor category's annual rate ('labor_category', paired with
//  labor_category_id — added Sept 2026, VeroCFO_Manufacturing_
//  Costing_Redesign_v1_0.md, Section 3, migration 000260). See
//  migration 000088 header for why these share one table instead of
//  separate ones.
//
//  Draft/Active/Superseded lifecycle (VeroCFO_Manufacturing_
//  Flexibility_Design_v1_0.md, Section 2 — see migration 000255):
//  the key (company, fiscal_year, rate_type, overhead_pool_id,
//  labor_category_id) can now have several versions over time. Only
//  one version per key may be status = Active at a time — enforced in
//  StandardRateService, never at the DB level, same pattern as
//  BillOfMaterial.
//
//  effectiveRate() resolves the rate that actually applies for a
//  given year/month WITHIN THIS SPECIFIC VERSION — a monthly override
//  if one exists on this row, otherwise this row's own annual_rate.
//  This is deliberately NOT the "which version applies at all" logic
//  — that's StandardRateService::resolveEffective(), which picks the
//  correct row first, then calls this method on it. Callers
//  (ProductionOrderService) always go through the service, never this
//  method directly.
//
//  @layer    Model
//  @depends  BelongsToCompany, FiscalYear, OverheadPool, LaborCategory, StandardRateMonthlyOverride
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandardRate extends Model
{
    use BelongsToCompany;

    const TYPE_LABOR          = 'labor';
    const TYPE_OVERHEAD_POOL  = 'overhead_pool';
    const TYPE_LABOR_CATEGORY = 'labor_category';

    const STATUS_DRAFT      = 'draft';
    const STATUS_ACTIVE     = 'active';
    const STATUS_SUPERSEDED = 'superseded';


    protected $fillable = [
        'company_id', 'fiscal_year_id', 'rate_type', 'overhead_pool_id', 'labor_category_id',
        'version', 'status', 'effective_from', 'annual_rate', 'notes', 'created_by',
    ];

    protected $casts = [
        'annual_rate'    => 'decimal:4',
        'effective_from' => 'date',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // Active or Superseded — the two statuses that ever actually
    // governed a real cost calculation, and are therefore eligible
    // for StandardRateService::resolveEffective()'s historical
    // lookup. Draft is deliberately excluded — it has never taken
    // effect. Mirrors BillOfMaterial::scopeEverEffective() exactly.
    public function scopeEverEffective(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_SUPERSEDED]);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    public function laborCategory(): BelongsTo
    {
        return $this->belongsTo(LaborCategory::class);
    }

    public function monthlyOverrides(): HasMany
    {
        return $this->hasMany(StandardRateMonthlyOverride::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── The single source of truth for "what THIS VERSION's rate is
    // for a given month" — NOT which version applies at all, see
    // class header. ──

    public function effectiveRate(int $year, int $month): string
    {
        $override = $this->monthlyOverrides()->where('year', $year)->where('month', $month)->first();

        return $override ? (string) $override->rate : (string) $this->annual_rate;
    }
}
