<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CommercialPaperLendingTier
//
//  One row of the rate card for an OD Against Commercial Paper
//  facility: "a cheque due within N days is lent at X% of its
//  face value." A facility can have several tiers (e.g. 30/60/90
//  days at different rates).
//
//  This model holds PRICING RULES only — it does not represent an
//  actual cheque. Matching a real deposited cheque to a tier
//  (finding the smallest due_within_days >= the cheque's actual
//  days-to-due) is part of a later build, once cheques themselves
//  are tracked.
//
//  Facility Renewal — Phase 2. Each tier row is now tagged to a
//  specific credit_facility_terms_histories chapter
//  (credit_facility_terms_history_id). A renewal creates a whole new
//  set of tiers tagged to the new chapter; the old chapter's rows
//  are frozen forever — see migration 2026_01_01_000135.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, CreditFacility, CreditFacilityTermsHistory
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialPaperLendingTier extends Model
{
    use BelongsToCompany;

    protected $table = 'commercial_paper_lending_tiers';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'credit_facility_terms_history_id',
        'due_within_days',
        'lending_rate_pct',
    ];

    protected $casts = [
        'due_within_days'  => 'integer',
        'lending_rate_pct'  => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class);
    }

    public function termsHistory(): BelongsTo
    {
        return $this->belongsTo(CreditFacilityTermsHistory::class, 'credit_facility_terms_history_id');
    }
}
