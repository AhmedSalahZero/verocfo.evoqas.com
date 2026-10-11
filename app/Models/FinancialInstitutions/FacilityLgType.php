<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityLgType
//
//  One row of the rate card for a Letter of Guarantee facility:
//  "an LG of type X carries Y% cash cover, Z% commission billed
//  every N months, with a minimum fee floor and a one-time
//  issuance fee." A facility has one row per LG type it supports.
//
//  This model holds PRICING RULES only — it does not represent an
//  actual issued guarantee (beneficiary, amount, expiry). That is
//  a later build (LG Issuance layer), designed separately once
//  Mahmoud shares that form.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityLgType extends Model
{
    use BelongsToCompany;

    protected $table = 'facility_lg_types';

    // Default LG types shown in the UI — the person can add custom
    // rows beyond these (confirmed business rule), so this list is
    // a UI convenience only, never enforced at the database level.
    public const DEFAULT_TYPES = [
        'Bid Bond',
        'Final LG',
        'Advanced Payment LG',
        'Performance LG',
    ];

    public const COMMISSION_INTERVALS = [
        'monthly'     => 'Monthly',
        'quarterly'   => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual'      => 'Annual',
    ];

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'lg_type',
        'cash_cover_pct',
        'commission_rate_pct',
        'commission_interval',
        'min_commission_fee_amount',
        'issuance_fee_amount',
        'sort_order',
    ];

    protected $casts = [
        'cash_cover_pct'             => 'decimal:2',
        'commission_rate_pct'        => 'decimal:4',
        'min_commission_fee_amount'  => 'decimal:2',
        'issuance_fee_amount'        => 'decimal:2',
        'sort_order'                 => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class);
    }
}
