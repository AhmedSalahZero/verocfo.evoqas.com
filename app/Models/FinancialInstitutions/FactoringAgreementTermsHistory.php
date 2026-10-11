<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringAgreementTermsHistory
//
//  One "chapter" in a Factoring Agreement's dated timeline of terms.
//  The first chapter (is_original = true) is created automatically
//  the moment the agreement itself is created. Every renewal after
//  that adds one more chapter, dated to its own effective_date.
//  Old chapters are never edited by Renew — only by the normal Edit
//  screen, and only while they're the CURRENTLY LIVE chapter (see
//  FactoringAgreement::syncLatestTermsChapter()).
//
//  Mirrors CreditFacilityTermsHistory (OD family) — see migration
//  2026_01_01_000185 for the full field mapping.
//
//  @layer    Model
//  @depends  FactoringAgreement
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoringAgreementTermsHistory extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'factoring_agreement_terms_histories';

    protected $fillable = [
        'company_id', 'factoring_agreement_id', 'effective_date', 'end_date',
        'borrowing_rate', 'discount_margin_rate', 'discount_rate',
        'extended_payment_days', 'is_original', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_date'        => 'date',
        'end_date'               => 'date',
        'borrowing_rate'         => 'decimal:4',
        'discount_margin_rate'   => 'decimal:4',
        'discount_rate'          => 'decimal:4',
        'extended_payment_days'  => 'integer',
        'is_original'            => 'boolean',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FactoringAgreement::class, 'factoring_agreement_id');
    }
}
