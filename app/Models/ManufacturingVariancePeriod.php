<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ManufacturingVariancePeriod
//
//  One row per (company, period, variance_type, overhead_pool_id) —
//  the exact labor or overhead-pool variance PayrollRunService
//  calculated when a Payroll Run posted for that period. Manufacturing
//  Variance Closing Design v1.0, Section 1/Step 3 — this is the
//  starting figure the monthly Variance Closing step reads and pushes
//  down to individual orders, by hours used.
//
//  Written by PayrollRunService::post() alongside the existing
//  514/515 journal entry lines (same numbers, just also kept in a
//  queryable form instead of only living inside a posted JE).
//  Deleted by PayrollRunService::setToDraft() when that run is
//  reopened, same reversal spirit as its own JournalEntry reversal.
//
//  variance_type = 'labor' → overhead_pool_id is always null (Option
//  B, confirmed with Mahmoud: labor variance is one company-wide
//  figure, not split by Labor Category, since only Overhead
//  employees carry a pool assignment today — Direct Labor employees
//  have no equivalent per-category tag to split by).
//  variance_type = 'overhead' → overhead_pool_id is always set, one
//  row per pool, mirroring how 515 already posts one labeled line per
//  pool rather than a combined figure.
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRun, OverheadPool
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturingVariancePeriod extends Model
{
    use BelongsToCompany;

    const TYPE_LABOR    = 'labor';
    const TYPE_OVERHEAD = 'overhead';

    protected $fillable = [
        'company_id', 'payroll_run_id', 'period_year', 'period_month',
        'variance_type', 'overhead_pool_id', 'amount',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'amount'       => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }
}
