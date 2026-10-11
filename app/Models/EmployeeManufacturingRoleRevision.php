<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EmployeeManufacturingRoleRevision
//
//  One row per Manufacturing Role change (Direct Labor <-> Overhead)
//  on an Employee Payroll Profile — the role (plus, when Overhead,
//  which Overhead Pool) and the date it starts applying. Append-only;
//  never updated or deleted, same posture as EmployeeSalaryRevision
//  and EmployeeClassificationRevision. See migration 000258 header.
//
//  @layer    Model
//  @depends  EmployeePayrollProfile, OverheadPool, Company, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeManufacturingRoleRevision extends Model
{
    use BelongsToCompany;
    use SerializesDateOnly;

    const ROLE_DIRECT_LABOR = 'direct_labor';
    const ROLE_OVERHEAD     = 'overhead';

    // Append-only — no updated_at needed.
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'employee_payroll_profile_id',
        'role',
        'overhead_pool_id',
        'effective_date',
        'note',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function employeePayrollProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
