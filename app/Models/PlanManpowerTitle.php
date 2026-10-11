<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerTitle
//
//  One job title inside a plan's Manpower step — title-level, never
//  named individuals (confirmed design, Sept 2026). See migration
//  000244 header for the is_existing snapshot rationale.
//
//  @layer    Model
//  @depends  BelongsToCompany, Plan, PlanManpowerHiringLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanManpowerTitle extends Model
{
    use BelongsToCompany;

    public const CATEGORY_SALES           = 'sales';
    public const CATEGORY_MARKETING       = 'marketing';
    public const CATEGORY_GENERAL_ADMIN   = 'general_admin';
    public const CATEGORY_COST_OF_SERVICE = 'cost_of_service';
    public const CATEGORY_MANUFACTURING   = 'manufacturing';

    protected $fillable = [
        'company_id',
        'plan_id',
        'job_title',
        'expense_category',
        'is_existing',
        'existing_headcount',
        'avg_current_salary',
    ];

    protected $casts = [
        'is_existing'         => 'boolean',
        'existing_headcount'  => 'integer',
        'avg_current_salary'  => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function hiringLines(): HasMany
    {
        return $this->hasMany(PlanManpowerHiringLine::class)->orderBy('plan_year');
    }

    /** Planned new hires for a given plan year (1-based). 0 if none entered. */
    public function newHiresForYear(int $planYear): int
    {
        return (int) ($this->hiringLines->firstWhere('plan_year', $planYear)?->new_hires_count ?? 0);
    }
}
