<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringAgreement
//
//  Factoring agreements — direct (with/without recourse) + reverse.
//
//  Direct + With Recourse    → COA 224001 — liability until invoice collected
//  Direct + Without Recourse → AR cleared directly — no liability created
//  Reverse                   → COA 224002 — company repays factoring co.
//
//  v1.1 — Agreement Renewal added (migration 000185), mirroring the
//  OD-family "dated chapter timeline" pattern (CreditFacility /
//  CreditFacilityTermsHistory). Edit always corrects whichever
//  chapter is CURRENTLY live, in place — only renew() ever inserts
//  a new dated chapter. end_date plays the role ODA's review_date
//  plays: it can never carry forward on a renewal, the agreement
//  must always state its own new one.
//
//  @layer    Model
//  @depends  FactoringCompany, ChartOfAccount, Partner,
//            FactoringAgreementTermsHistory
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Partner;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class FactoringAgreement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'factoring_agreements';

    protected $fillable = [
        'company_id', 'factoring_company_id', 'agreement_reference', 'agreement_type',
        'recourse_type', 'borrowing_rate', 'discount_margin_rate', 'discount_rate',
        'follows_corridor_rate', 'coa_account_id',
        'eligible_partner_id', 'extended_payment_days',
        'start_date', 'end_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'borrowing_rate'        => 'decimal:4',
        'discount_margin_rate'  => 'decimal:4',
        'discount_rate'         => 'decimal:4',
        'follows_corridor_rate' => 'boolean',
        'start_date'            => 'date',
        'end_date'              => 'date',
    ];

    public function factoringCompany(): BelongsTo { return $this->belongsTo(FactoringCompany::class); }
    public function coaAccount(): BelongsTo       { return $this->belongsTo(ChartOfAccount::class, 'coa_account_id'); }
    public function eligiblePartner(): BelongsTo  { return $this->belongsTo(Partner::class, 'eligible_partner_id'); }

    /**
     * Agreement Renewal — the dated timeline of terms ("chapters").
     * Ordered oldest-first so the Original chapter is always index 0
     * and the latest is always last().
     */
    public function termsHistories(): HasMany
    {
        return $this->hasMany(FactoringAgreementTermsHistory::class, 'factoring_agreement_id')
                    ->orderBy('effective_date')
                    ->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder { return $query->where('status', 'active'); }

    public function isDirect(): bool  { return $this->agreement_type === 'direct'; }
    public function isReverse(): bool { return $this->agreement_type === 'reverse'; }
    public function isWithRecourse(): bool { return $this->recourse_type === 'with_recourse'; }

    // ── Agreement Renewal ────────────────────────────────────────

    /** True once this agreement has been renewed at least once. */
    public function hasRenewals(): bool
    {
        return $this->termsHistories()->count() > 1;
    }

    /** The chapter currently in force — what Edit corrects in place. */
    public function getLatestTerms(): ?FactoringAgreementTermsHistory
    {
        return $this->termsHistories()->reorder('effective_date', 'desc')->orderByDesc('id')->first();
    }

    /**
     * Defensive safety net, same role as
     * CreditFacility::createOriginalTermsHistory() — an agreement
     * should always get its "chapter one" row the moment it's
     * created (see FactoringService::createAgreement()), but this
     * backfills it if one somehow reaches renew() with zero chapters.
     */
    public function createOriginalTermsHistory(): FactoringAgreementTermsHistory
    {
        return $this->termsHistories()->create([
            'company_id'             => $this->company_id,
            'effective_date'         => $this->start_date,
            'end_date'               => $this->end_date,
            'borrowing_rate'         => $this->borrowing_rate,
            'discount_margin_rate'   => $this->discount_margin_rate,
            'discount_rate'          => $this->discount_rate,
            'extended_payment_days'  => $this->extended_payment_days,
            'is_original'            => true,
            'notes'                  => 'Original agreement terms.',
        ]);
    }

    /**
     * Corrects the CURRENTLY LIVE chapter's numbers in place — called
     * after every Edit save. Never touches effective_date; only
     * renew()/deleteLatestRenewal() ever move it.
     */
    public function syncLatestTermsChapter(): void
    {
        $latest = $this->getLatestTerms() ?? $this->createOriginalTermsHistory();

        $latest->update([
            'end_date'               => $this->end_date,
            'borrowing_rate'         => $this->borrowing_rate,
            'discount_margin_rate'   => $this->discount_margin_rate,
            'discount_rate'          => $this->discount_rate,
            'extended_payment_days'  => $this->extended_payment_days,
        ]);
    }

    /**
     * Records a renewal: a brand-new dated chapter. Anything left
     * null in $newTerms carries forward the previous chapter's
     * value — the user only enters what actually changed. end_date
     * is the one field that can never carry forward (a renewal must
     * always state its own new agreement end date) — enforced by
     * the Request, not here.
     *
     * borrowing_rate is deliberately excluded from $newTerms by the
     * caller whenever follows_corridor_rate is true — see
     * FactoringService::renewAgreement() — so it always falls
     * through to "carry forward from previous chapter".
     */
    public function renew(array $newTerms, User $actor): FactoringAgreementTermsHistory
    {
        if ($this->termsHistories()->count() === 0) {
            $this->createOriginalTermsHistory();
        }

        $previous = $this->getLatestTerms();

        if ($previous && $newTerms['effective_date'] <= $previous->effective_date) {
            throw new \DomainException(
                "A renewal date must be after the agreement's most recent chapter date ({$previous->effective_date->format('d-m-Y')})."
            );
        }

        $currentEndDate = $previous?->end_date ?: $this->end_date;
        if ($currentEndDate && $newTerms['effective_date'] <= $currentEndDate) {
            throw new \DomainException(
                "A renewal date must be after the agreement's current end date ({$currentEndDate->format('d-m-Y')})."
            );
        }

        if (empty($newTerms['end_date'])) {
            throw new \DomainException('A renewal must include a new agreement end date.');
        }

        $before = $this->toArray();

        $borrowingRate = $newTerms['borrowing_rate']       ?? $previous?->borrowing_rate       ?? $this->borrowing_rate;
        $marginRate    = $newTerms['discount_margin_rate'] ?? $previous?->discount_margin_rate ?? $this->discount_margin_rate;
        $discountRate  = bcadd((string) $borrowingRate, (string) $marginRate, 4);

        $chapter = $this->termsHistories()->create([
            'company_id'             => $this->company_id,
            'effective_date'         => $newTerms['effective_date'],
            'end_date'               => $newTerms['end_date'],
            'borrowing_rate'         => $borrowingRate,
            'discount_margin_rate'   => $marginRate,
            'discount_rate'          => $discountRate,
            'extended_payment_days'  => $newTerms['extended_payment_days'] ?? $previous?->extended_payment_days ?? $this->extended_payment_days,
            'notes'                  => $newTerms['notes'] ?? null,
            'is_original'            => false,
            'created_by'             => $actor->id,
        ]);

        // Keep the convenience columns on factoring_agreements itself
        // in sync with "the latest chapter" — existing code reads
        // these columns directly for present-day figures.
        $this->update([
            'end_date'               => $chapter->end_date,
            'borrowing_rate'         => $chapter->borrowing_rate,
            'discount_margin_rate'   => $chapter->discount_margin_rate,
            'discount_rate'          => $chapter->discount_rate,
            'extended_payment_days'  => $chapter->extended_payment_days,
        ]);

        AuditLog::record($actor, 'factoring_agreement.renewed', $this, $before, $this->fresh()->toArray());

        return $chapter;
    }

    /**
     * Undoes the most recent renewal only — never an earlier one out
     * of order. On success, the agreement's convenience columns
     * revert to whatever the next-most-recent chapter says — which
     * becomes "Original" again if that was the only renewal.
     */
    public function deleteLatestRenewal(User $actor): void
    {
        $latest = $this->getLatestTerms();

        if (! $latest || $latest->is_original) {
            throw new \DomainException('There is no renewal to delete — this agreement is still on its original terms.');
        }

        $before = $this->toArray();

        $latest->delete();

        $newLatest = $this->getLatestTerms();

        $this->update([
            'end_date'               => $newLatest->end_date,
            'borrowing_rate'         => $newLatest->borrowing_rate,
            'discount_margin_rate'   => $newLatest->discount_margin_rate,
            'discount_rate'          => $newLatest->discount_rate,
            'extended_payment_days'  => $newLatest->extended_payment_days,
        ]);

        // NOTE: audit_log.action is capped at varchar(30).
        // 'factoring_agreement.renewal_undo' is 32 chars and would
        // overflow it — 'renew_undo' (30 chars exactly) is used
        // instead. credit_facility.renewal_undo fits only because
        // "credit_facility" is shorter than "factoring_agreement".
        AuditLog::record($actor, 'factoring_agreement.renew_undo', $this, $before, $this->fresh()->toArray());
    }
}
