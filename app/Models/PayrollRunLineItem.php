<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunLineItem
//
//  One generic addition or deduction on a single employee's
//  PayrollRunLine within a Payroll Run — see migration 000228's
//  header for the full design reasoning (forward-compatible with a
//  future Leave Management module).
//
//  type='addition' never carries a deduction_reason_id (a bonus
//  doesn't need one). type='deduction' requires one — its
//  DeductionReason::behavior (earned_reduction vs payout_penalty)
//  decides which side of PayrollRunLine's totals it lands on; see
//  PayrollRunService::recalculateLine().
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRunLine, DeductionReason, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunLineItem extends Model
{
    use BelongsToCompany;

    const TYPE_ADDITION  = 'addition';
    const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'company_id', 'payroll_run_line_id', 'type', 'deduction_reason_id',
        'amount', 'description', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollRunLine(): BelongsTo
    {
        return $this->belongsTo(PayrollRunLine::class);
    }

    public function deductionReason(): BelongsTo
    {
        return $this->belongsTo(DeductionReason::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAddition(): bool
    {
        return $this->type === self::TYPE_ADDITION;
    }

    public function isDeduction(): bool
    {
        return $this->type === self::TYPE_DEDUCTION;
    }
}
