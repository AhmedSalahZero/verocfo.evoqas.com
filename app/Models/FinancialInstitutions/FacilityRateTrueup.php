<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityRateTrueup
//
//  Record that a back-dated CBE corridor rate re-priced a facility's
//  theoretical daily interest. See migration 000313 and
//  BorrowingRateService. Audit M24 (Oct 2026). Append-only.
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility, CbeCorridorRate
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;

class FacilityRateTrueup extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_rate_trueups';

    protected $fillable = [
        'company_id', 'credit_facility_id', 'cbe_corridor_rate_id', 'from_date', 'to_date',
        'old_interest_rate', 'new_interest_rate', 'old_theoretical', 'new_theoretical',
        'difference', 'posted_interest', 'affects_posted_months', 'created_by',
    ];

    protected $casts = [
        'from_date'             => 'date',
        'to_date'               => 'date',
        'old_interest_rate'     => 'decimal:4',
        'new_interest_rate'     => 'decimal:4',
        'old_theoretical'       => 'decimal:6',
        'new_theoretical'       => 'decimal:6',
        'difference'            => 'decimal:6',
        'posted_interest'       => 'decimal:2',
        'affects_posted_months' => 'boolean',
    ];
}
