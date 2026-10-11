<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EosbProvisionRunLine
//
//  One employee's EOSB provision within one run. base_salary and
//  eosb_rate_percent are SNAPSHOTS at run-creation time — see
//  migration 000235 header. provision_amount = base_salary ×
//  eosb_rate_percent / 100, calculated once when the line is created
//  (EosbProvisionRunService::create()) — never recalculated from a
//  later profile change, same reasoning as every other snapshot in
//  this app.
//
//  @layer    Model
//  @depends  BelongsToCompany, EosbProvisionRun, Partner,
//            EmployeePayrollProfile, Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EosbProvisionRunLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'eosb_provision_run_id', 'partner_id', 'employee_payroll_profile_id',
        'base_salary', 'eosb_rate_percent', 'currency_id', 'provision_amount',
    ];

    protected $casts = [
        'base_salary'        => 'decimal:2',
        'eosb_rate_percent'  => 'decimal:2',
        'provision_amount'   => 'decimal:2',
    ];

    public function eosbProvisionRun(): BelongsTo
    {
        return $this->belongsTo(EosbProvisionRun::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function employeePayrollProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
