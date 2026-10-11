<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilitySettlementAllocation
//
//  One line of a FacilitySettlement — how much of that settlement
//  paid off one specific charge or withdrawal. See migration 000217
//  header. allocatable_type/allocatable_id is a hand-rolled
//  polymorphic pair (not Eloquent morphTo) matching this project's
//  existing preference for explicit enums.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, FacilitySettlement,
//            FacilityLiabilityCharge, FacilityWithdrawal
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilitySettlementAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'facility_settlement_allocations';

    const TYPE_CHARGE     = 'facility_liability_charge';
    const TYPE_WITHDRAWAL = 'facility_withdrawal';

    protected $fillable = [
        'company_id',
        'facility_settlement_id',
        'allocatable_type',
        'allocatable_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function facilitySettlement(): BelongsTo
    {
        return $this->belongsTo(FacilitySettlement::class);
    }

    /**
     * Resolves the actual charge or withdrawal row this allocation
     * paid off. Not a real Eloquent relation (the type varies), so
     * this is a plain lookup, used by reversal/resettlement.
     */
    public function allocatable(): FacilityLiabilityCharge|FacilityWithdrawal|null
    {
        return match ($this->allocatable_type) {
            self::TYPE_CHARGE     => FacilityLiabilityCharge::find($this->allocatable_id),
            self::TYPE_WITHDRAWAL => FacilityWithdrawal::find($this->allocatable_id),
            default               => null,
        };
    }
}
