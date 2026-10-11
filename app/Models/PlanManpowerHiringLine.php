<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerHiringLine
//
//  Planned new hires — one row per plan_manpower_title per plan year.
//  See migration 000245 header. Just a headcount count; salary comes
//  from the parent title's avg_current_salary, grown by the plan's
//  manpower_annual_increase_pct in the calculation engine.
//
//  @layer    Model
//  @depends  BelongsToCompany, PlanManpowerTitle
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanManpowerHiringLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'plan_manpower_title_id',
        'plan_year',
        'new_hires_count',
    ];

    protected $casts = [
        'plan_year'       => 'integer',
        'new_hires_count' => 'integer',
    ];

    public function planManpowerTitle(): BelongsTo
    {
        return $this->belongsTo(PlanManpowerTitle::class);
    }
}
