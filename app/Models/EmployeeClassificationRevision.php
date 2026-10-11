<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EmployeeClassificationRevision
//
//  One row per Expense Category change on an Employee Payroll
//  Profile — the category + the date it starts applying. Append-only;
//  never updated or deleted (a mistake gets corrected by adding a new
//  row, same posture as EmployeeSalaryRevision). See migration 000256
//  header for the full design reasoning and how PayrollRunService
//  picks the right one.
//
//  @layer    Model
//  @depends  EmployeePayrollProfile, Company, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeClassificationRevision extends Model
{
    use BelongsToCompany;
    use SerializesDateOnly;

    // Append-only — no updated_at needed.
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'employee_payroll_profile_id',
        'expense_category',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
