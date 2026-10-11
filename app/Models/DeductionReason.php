<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  DeductionReason
//
//  Company-configurable lookup list for payroll deduction lines —
//  see migration 000227 header for the full reasoning on why
//  `behavior` exists and how it keeps Payroll forward-compatible
//  with a future Leave Management module.
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRunLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionReason extends Model
{
    use BelongsToCompany;

    const BEHAVIOR_EARNED_REDUCTION = 'earned_reduction';
    const BEHAVIOR_PAYOUT_PENALTY   = 'payout_penalty';

    const ALL_BEHAVIORS = [
        self::BEHAVIOR_EARNED_REDUCTION,
        self::BEHAVIOR_PAYOUT_PENALTY,
    ];

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'behavior', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payrollRunLines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function isEarnedReduction(): bool
    {
        return $this->behavior === self::BEHAVIOR_EARNED_REDUCTION;
    }
}
