<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityDailyBalance
//
//  Running daily balance log for OD facilities.
//  Auto-maintained by every transaction (withdrawal / settlement / charge).
//
//  Serves three purposes:
//    1. Real-time OD balance at any historical date for CFO dashboard
//    2. Monthly interest calculation: Sum(closing_balance × rate / 360)
//    3. Highest Debit Balance tracking: identifies quarter peak for HDB fee
//
//  Interest formula (Egyptian banking standard — 360 days):
//    daily_interest = closing_balance × (annual_rate / 100) / 360
//    All calculations use bcmath (6dp precision before rounding to 2dp)
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class FacilityDailyBalance extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_daily_balances';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'balance_date',
        'opening_balance',
        'withdrawals_today',
        'charges_today',
        'settlements_today',
        'closing_balance',
        'daily_interest_amount',
        'is_quarter_peak',
    ];

    protected $casts = [
        'balance_date'          => 'date',
        'opening_balance'       => 'decimal:2',
        'withdrawals_today'     => 'decimal:2',
        'charges_today'         => 'decimal:2',
        'settlements_today'     => 'decimal:2',
        'closing_balance'       => 'decimal:2',
        'daily_interest_amount' => 'decimal:6',
        'is_quarter_peak'       => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('balance_date', $year)
                     ->whereMonth('balance_date', $month);
    }

    public function scopeForQuarter(Builder $query, int $year, int $quarter): Builder
    {
        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth   = $startMonth + 2;
        return $query->whereYear('balance_date', $year)
                     ->whereMonth('balance_date', '>=', $startMonth)
                     ->whereMonth('balance_date', '<=', $endMonth);
    }

    public function scopeQuarterPeaks(Builder $query): Builder
    {
        return $query->where('is_quarter_peak', true);
    }
}
