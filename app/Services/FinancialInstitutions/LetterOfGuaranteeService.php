<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfGuaranteeService
//
//  All business logic for Letter of Guarantee (227 LG Contingent
//  Liability / 138001 LG Cash Cover) facility AGREEMENTS.
//
//  This is deliberately an AGREEMENT-level service only — it does
//  NOT issue individual guarantees. Confirmed business model (from
//  the LG Agreement form image, discussed and agreed with Mahmoud):
//
//    One LG facility (agreement with a bank) → many LG TYPES
//    (Bid Bond, Final LG, Advanced Payment LG, Performance LG, or
//    a custom type), each with its own cash cover %, commission
//    rate, commission billing interval, minimum commission fee,
//    and one-time issuance fee. This is a RATE CARD, not a list
//    of issued guarantees.
//
//  Layer 1C responsibilities (this service, today):
//    - Create / update the LG facility agreement
//    - Create / replace the LG type rate card (facility_lg_types)
//    - Validate: no two rate-card rows share the same lg_type
//      (case-insensitive — "bid bond" and "Bid Bond" collide)
//    - No interest rate, no outstanding balance, no daily balance
//      log — this facility type doesn't draw down cash the way
//      Clean OD / CP OD / Contract-SO do. There is nothing to
//      settle at the agreement level.
//
//  Deliberately NOT in this service (by design, confirmed before
//  building):
//    - No individual LG issuance (beneficiary, amount, expiry,
//      cash cover account). That is a separate future layer —
//      the LG equivalent of Clean OD's withdrawals — designed
//      once Mahmoud shares the LG Issuance form. The dormant
//      lg_beneficiary / lg_margin_pct / lg_cash_cover_account_id /
//      expiry_date columns on credit_facilities are reserved for
//      that table's design, not used here.
//    - No coa_account_id handling — LG agreements don't post to a
//      user-picked COA account (migration 000065 made the column
//      nullable for this reason). The fixed 227/138 posting pair
//      is an Issuance-layer concern.
//
//  Layer 6 responsibilities (added later, once LG Issuance exists):
//    - issueLg()      — creates an issuance row, posts 138001 Dr / 227 Cr
//    - liquidateLg()  — reverses the contingent pair, posts real liability
//    - expireLg()     — reverses the contingent pair, no cash movement
//    - postCommission() — periodic commission billing per lg_type's interval
//
//  @layer    Service
//  @depends  CreditFacility, FacilityLgType, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Currency;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LetterOfGuaranteeService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                // withoutGlobalScopes() eager load — see class header
                // in CommercialPaperOverdraftService for the full
                // writeup of this recurring bug. Bank has its own
                // BelongsToCompany + CompanyScope, so the shorthand
                // 'bank:id,name_en' silently drops rows.
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en', 'name_ar'),
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                'lgTypes',
                // Facility Renewal — needed so the index can show a
                // "N renewals" badge and open the read-only history
                // modal without a second request per row.
                'termsHistories' => fn ($q) => $q->orderBy('effective_date')->orderBy('id'),
            ])
            ->where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'letter_guarantee')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Portfolio Stats (for index page KPI cards) ──────────────────

    /**
     * Portfolio-wide aggregates for the index page. Same currency
     * safety rule as every other facility service — facility_limit
     * is never summed across currencies, grouped by currency instead.
     *
     * No utilization / drawn figures here — LG agreements don't
     * carry an outstanding balance at the agreement level (that
     * only exists once individual issuances are tracked). In its
     * place: total LG types configured, and agreements approaching
     * their review/renewal date (reuses the "near limit" idea from
     * OD facilities, but keyed on date instead of amount).
     */
    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'letter_guarantee')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s));

        $totalCount  = $base()->count();
        $activeCount = $base()->where('status', 'active')->count();

        $byCurrency = $base()
            ->join('currencies', 'currencies.id', '=', 'credit_facilities.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->select([
                'currencies.code as currency_code',
                DB::raw('SUM(credit_facilities.facility_limit) as total_limit'),
            ])
            ->orderBy('currencies.code')
            ->get();

        $facilityIds = $base()->pluck('id');

        $totalLgTypes = FacilityLgType::where('company_id', $companyId)
            ->whereIn('credit_facility_id', $facilityIds)
            ->count();

        $nearReviewCount = $base()
            ->where('status', 'active')
            ->whereNotNull('review_date')
            ->whereBetween('review_date', [now(), now()->addDays(30)])
            ->count();

        return [
            'total_count'        => $totalCount,
            'active_count'       => $activeCount,
            'by_currency'        => $byCurrency,
            'total_lg_types'     => $totalLgTypes,
            'near_review_count'  => $nearReviewCount,
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $lgTypes = $data['lg_types'] ?? [];
            $this->validateLgTypes($lgTypes);
            $this->validateForeignKeys($companyId, $data);

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'letter_guarantee';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';
            $data['coa_account_id'] = null; // Agreement level — see class header

            unset($data['lg_types']);

            $facility = CreditFacility::create($data);

            $this->seedLgTypes($companyId, $facility, $lgTypes);

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'lgTypes']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $lgTypes = $data['lg_types'] ?? [];
            $this->validateLgTypes($lgTypes);
            $this->validateForeignKeys($companyId, $data);

            $before = $facility->toArray();

            $data['coa_account_id'] = null; // Agreement level — see class header
            unset($data['lg_types']);

            $facility->update($data);

            // Replace the rate card entirely with what was submitted —
            // same approach CP Overdraft uses for its lending tiers.
            $facility->lgTypes()->delete();
            $this->seedLgTypes($companyId, $facility, $lgTypes);

            // Facility Renewal — Edit always corrects whichever chapter
            // is CURRENTLY live, in place (never locked by a renewal
            // having happened, same rule as the OD family). This never
            // creates a new chapter or touches effective_date; only
            // renewFacility() does that.
            $facility->syncLatestTermsChapter();

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'lgTypes']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * Records a renewal — a brand-new dated chapter of terms, PLUS the
     * renegotiated rate card (Terms & Conditions). Unlike the OD
     * family, an LG facility (agreement) has no borrowing rate,
     * margin, HDB, or admin fee to renegotiate — but it very much has
     * its own "terms and conditions": cash cover %, commission %,
     * commission interval, minimum commission fee, and issuance fee,
     * per LG type. This was the gap Mahmoud flagged — the first pass
     * of this feature only let the limit/review date change, treating
     * the renewal like a Clean OD renewal when the real parallel is
     * the rate-card-driven Letter of Credit facility.
     *
     * The chapter mechanism (review_date / facility_limit) reuses
     * CreditFacility::renew() exactly as already proven for the OD
     * family. The rate card itself is NOT chapter-versioned (facility_
     * lg_types has never been dated history — a plain Edit already
     * replaces it wholesale the same way), so a renewal replaces it
     * the same way Edit does: this action, not a new lg_types table,
     * IS the audit trail for "what changed and when" — captured
     * explicitly in the AuditLog record below (old vs new rate card),
     * since $facility->toArray() doesn't include the lgTypes relation.
     *
     * IMPORTANT — this is the LG FACILITY (agreement) renewal, not
     * the LG ISSUANCE renewal (see LgIssuanceService::renew() — a
     * completely separate lifecycle action on an individual issued
     * guarantee, with its own cash cover / commission rate changes).
     */
    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $lgTypes = $data['lg_types'] ?? [];
            $this->validateLgTypes($lgTypes);

            $beforeLgTypes = $facility->lgTypes()->orderBy('sort_order')->get()->toArray();

            // Dated chapter: effective_date / review_date / facility_limit.
            // CreditFacility::renew() also writes its own audit_log row
            // for this part (credit_facility.renewed).
            $facility->renew($data, $actor);

            // Rate card: replaced wholesale with the renewed terms —
            // same replace-and-reseed approach update() already uses.
            $facility->lgTypes()->delete();
            $this->seedLgTypes($companyId, $facility, $lgTypes);

            $afterLgTypes = $facility->fresh('lgTypes')->lgTypes()->orderBy('sort_order')->get()->toArray();

            AuditLog::record(
                $actor,
                'credit_facility.lg_renewed',
                $facility,
                ['lg_types' => $beforeLgTypes],
                ['lg_types' => $afterLgTypes]
            );

            return $facility->fresh(['bank', 'currency', 'lgTypes']);
        });
    }

    /**
     * Undoes the facility's most recent renewal. See
     * CreditFacility::deleteLatestRenewal() for the full rule set —
     * its withdrawal/liability-charge blocking checks will simply
     * never find anything for an LG facility (those tables only ever
     * get populated by the OD family), so the only real gate here is
     * "this isn't the Original chapter".
     */
    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency', 'lgTypes']);
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Each LG type can only appear once per facility. Case-insensitive
     * so "Bid Bond" and "bid bond" are treated as the same type — the
     * database unique index is case-sensitive on most collations, so
     * this check exists to catch what the DB constraint would miss,
     * with a clearer message than a raw duplicate-entry error either way.
     */
    private function validateLgTypes(array $lgTypes): void
    {
        if (empty($lgTypes)) {
            throw new \DomainException(
                'At least one LG type is required — the rate card cannot be empty.'
            );
        }

        $seen = [];

        foreach ($lgTypes as $row) {
            $type = strtolower(trim($row['lg_type'] ?? ''));

            if ($type === '') {
                throw new \DomainException('Every LG type row needs a name.');
            }

            if (in_array($type, $seen, true)) {
                throw new \DomainException(
                    "\"{$row['lg_type']}\" appears more than once — each LG type can only have one set of terms."
                );
            }

            $seen[] = $type;
        }
    }

    private function seedLgTypes(int $companyId, CreditFacility $facility, array $lgTypes): void
    {
        foreach ($lgTypes as $index => $row) {
            if (!isset($row['lg_type']) || trim($row['lg_type']) === '') {
                continue; // skip incomplete rows
            }

            FacilityLgType::create([
                'company_id'                 => $companyId,
                'credit_facility_id'         => $facility->id,
                'lg_type'                    => trim($row['lg_type']),
                'cash_cover_pct'              => $row['cash_cover_pct']              ?? 0,
                'commission_rate_pct'         => $row['commission_rate_pct']         ?? 0,
                'commission_interval'         => $row['commission_interval']         ?? 'quarterly',
                'min_commission_fee_amount'   => $row['min_commission_fee_amount']   ?? 0,
                'issuance_fee_amount'         => $row['issuance_fee_amount']         ?? 0,
                'sort_order'                  => $index,
            ]);
        }
    }

    /**
     * Rule 2.5 — Cross-company FK validation. Same pattern as every
     * other facility service — currency_id comes from a dropdown
     * mixing system-shared rows (company_id = NULL) with this
     * company's own rows; the controller's exists:table,id rule only
     * confirms the row exists somewhere, not that it's visible to
     * this company.
     */
    private function validateForeignKeys(int $companyId, array $data): void
    {
        if (!empty($data['currency_id'])) {
            $belongsToCompany = Currency::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data['currency_id'])
                ->exists();

            if (! $belongsToCompany) {
                throw new \DomainException('Selected currency is not available for this company.');
            }
        }
    }
}
