<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunLine
//
//  One employee's pay within one PayrollRun. base_salary/currency_id/
//  expense_category are snapshots from EmployeePayrollProfile at the
//  moment the run was created — see migration 000228 header for why.
//  additions_total/earned_reduction_total/payout_penalty_total/
//  gross_pay/net_pay are all computed by PayrollRunService from this
//  line's own payroll_run_line_items — never set directly.
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRun, Partner, EmployeePayrollProfile, Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'payroll_run_id', 'partner_id', 'employee_payroll_profile_id',
        'base_salary', 'currency_id', 'expense_category',
        'additions_total', 'earned_reduction_total', 'payout_penalty_total', 'gross_pay',
        'tax_amount', 'social_insurance_employee_amount', 'social_insurance_employer_amount',
        'advance_deduction_amount', 'net_pay',
    ];

    protected $casts = [
        'base_salary'                       => 'decimal:2',
        'additions_total'                   => 'decimal:2',
        'earned_reduction_total'            => 'decimal:2',
        'payout_penalty_total'              => 'decimal:2',
        'gross_pay'                         => 'decimal:2',
        'tax_amount'                        => 'decimal:2',
        'social_insurance_employee_amount'  => 'decimal:2',
        'social_insurance_employer_amount'  => 'decimal:2',
        'advance_deduction_amount'          => 'decimal:2',
        'net_pay'                           => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
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

    public function lineItems(): HasMany
    {
        return $this->hasMany(PayrollRunLineItem::class);
    }

    public function isManufacturing(): bool
    {
        return $this->expense_category === EmployeePayrollProfile::CATEGORY_MANUFACTURING;
    }
}
