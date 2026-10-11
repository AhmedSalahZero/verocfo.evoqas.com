<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityLcType
//
//  One row of the rate card for a Letter of Credit facility — see
//  FacilityLgType for the identical pattern. No commission_interval
//  here (confirmed absent from LC's rate card, unlike LG).
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityLcType extends Model
{
    use BelongsToCompany;

    protected $table = 'facility_lc_types';

    public const DEFAULT_TYPES = [
        'Sight LC',
        'Deferred',
        'Cash Against Document',
    ];

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'lc_type',
        'cash_cover_pct',
        'commission_rate_pct',
        'min_commission_fee_amount',
        'issuance_fee_amount',
        'sort_order',
    ];

    protected $casts = [
        'cash_cover_pct'            => 'decimal:2',
        'commission_rate_pct'       => 'decimal:4',
        'min_commission_fee_amount' => 'decimal:2',
        'issuance_fee_amount'       => 'decimal:2',
        'sort_order'                => 'integer',
    ];

    public function creditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class);
    }
}
