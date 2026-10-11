<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CreditFacilityTermsHistory
//
//  One "chapter" in an OD-family facility's dated timeline of terms.
//  The first chapter (is_original = true) is created automatically
//  the moment the facility itself is created. Every renewal after
//  that adds one more chapter, dated to its own effective_date.
//  Old chapters are never edited by Renew — only by the normal Edit
//  screen, and only while they're the CURRENTLY LIVE chapter (see
//  CreditFacility::syncLatestTermsChapter()).
//
//  See migration 2026_01_01_000134 for the full design rationale.
//
//  @layer    Model
//  @depends  CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditFacilityTermsHistory extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'credit_facility_terms_histories';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'effective_date',
        'facility_limit',
        'borrowing_rate',
        'bank_margin_rate',
        'min_interest_rate',
        'hdb_rate',
        'admin_fee_rate',
        'settled_max_days',
        'max_lending_limit_per_customer',
        'max_lending_limit_per_contract',
        // Fully Secured OD — was missing here despite being written
        // by CreditFacility::renew() since migration 000161; every
        // renewal chapter silently stored NULL for both until now
        // (bug fix bundled with migration 000165).
        'fsod_td_amount',
        'fsod_td_lending_pct',
        // Fully Secured LC — new, migration 000165.
        'lc_td_amount',
        'lc_td_lending_pct',
        'review_date',
        'notes',
        'is_original',
        'created_by',
    ];

    protected $casts = [
        'effective_date'                 => 'date',
        'review_date'                    => 'date',
        'facility_limit'                 => 'decimal:2',
        'borrowing_rate'                 => 'decimal:4',
        'bank_margin_rate'               => 'decimal:4',
        'min_interest_rate'              => 'decimal:4',
        'hdb_rate'                       => 'decimal:4',
        'admin_fee_rate'                 => 'decimal:4',
        'max_lending_limit_per_customer' => 'decimal:2',
        'max_lending_limit_per_contract' => 'decimal:2',
        'fsod_td_amount'                 => 'decimal:2',
        'fsod_td_lending_pct'            => 'decimal:2',
        'lc_td_amount'                   => 'decimal:2',
        'lc_td_lending_pct'              => 'decimal:2',
        'settled_max_days'               => 'integer',
        'is_original'                    => 'boolean',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'credit_facility_id');
    }
}
