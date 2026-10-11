<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfCreditService
//
//  Business logic for Letter of Credit (facility_type = 'import_lc')
//  facility AGREEMENTS — same agreement + rate-card pattern as
//  LetterOfGuaranteeService, plus a Financing Terms & Conditions
//  section (bank-funded revolving debt, reusing Clean OD's rate
//  columns) and a Fully Secured variant with computed Limit.
//
//  Individual LC issuances (the equivalent of LG's future Issuance
//  layer — account_number, outstanding_balance, balance_date,
//  settled_max_days, New/Opening posting mode) are explicitly NOT
//  handled here — confirmed with Mahmoud these belong to a future
//  LC Issuance build, same shape as LgIssuance.
//
//  @layer    Service
//  @depends  CreditFacility, FacilityLcType, BankAccount,
//            Currency, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Currency;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLcType;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LetterOfCreditService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en', 'name_ar'),
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                'lcTypes',
                // Facility Renewal — needed so the index can show a
                // "N renewals" badge and open the read-only history
                // modal without a second request per row.
                'termsHistories' => fn ($q) => $q->orderBy('effective_date')->orderBy('id'),
            ])
            ->where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'import_lc')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'import_lc')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s));

        $totalCount  = $base()->count();
        $activeCount = $base()->where('status', 'active')->count();

        $byCurrency = $base()
            ->join('currencies', 'currencies.id', '=', 'credit_facilities.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->selectRaw('currencies.code as currency_code, SUM(credit_facilities.facility_limit) as total_limit')
            ->orderBy('currencies.code')
            ->get();

        $fullySecuredCount = $base()->where('lc_security_type', 'fully_secured')->count();

        $nearReviewCount = $base()
            ->where('status', 'active')
            ->whereNotNull('review_date')
            ->whereBetween('review_date', [now(), now()->addDays(30)])
            ->count();

        return [
            'total_count'         => $totalCount,
            'active_count'        => $activeCount,
            'by_currency'         => $byCurrency,
            'fully_secured_count' => $fullySecuredCount,
            'near_review_count'   => $nearReviewCount,
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $lcTypes = $data['lc_types'] ?? [];
            $this->validateLcTypes($lcTypes);
            $this->validateForeignKeys($companyId, $data);
            $this->applyComputedLimit($data);
            $this->applyInterestRate($data);

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'import_lc';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';
            $data['coa_account_id'] = null; // Agreement level — Issuance layer concern, same as LG

            unset($data['lc_types']);

            $facility = CreditFacility::create($data);
            $this->seedLcTypes($companyId, $facility, $lcTypes);

            // Facility Renewal — every facility gets its "chapter one"
            // row the moment it's created, same as every other type
            // that supports renewal.
            $facility->createOriginalTermsHistory();

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'lcTypes']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $lcTypes = $data['lc_types'] ?? [];
            $this->validateLcTypes($lcTypes);
            $this->validateForeignKeys($companyId, $data);
            $this->applyComputedLimit($data);
            $this->applyInterestRate($data);

            $before = $facility->toArray();

            $data['coa_account_id'] = null;
            unset($data['lc_types']);

            $facility->update($data);

            $facility->lcTypes()->delete();
            $this->seedLcTypes($companyId, $facility, $lcTypes);

            // Facility Renewal — Edit always corrects whichever chapter
            // is CURRENTLY live, in place (never locked by a renewal
            // having happened, same rule as every other renewable type).
            $facility->syncLatestTermsChapter();

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'lcTypes']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * Records a renewal — a brand-new dated chapter of Financing
     * Terms & Conditions (borrowing rate, bank margin, min interest,
     * HDB rate, admin fee, and either the typed Limit or the CD/TD
     * Lending Percentage depending on lc_security_type), PLUS the
     * renegotiated LC-type rate card (cash cover %, commission %,
     * minimum commission fee, issuance fee). This was the gap
     * Mahmoud flagged: LC had no renewal feature at all.
     *
     * The dated-chapter half reuses CreditFacility::renew() exactly
     * as already proven for Clean OD / CP OD / Contract Assignment OD
     * / Fully Secured OD — including the Fully Secured LC branch of
     * its facility_limit recomputation (TD Amount × Lending % ÷ 100,
     * mirroring Fully Secured OD's own formula). follows_corridor_rate
     * strips borrowing_rate from $data the same way Clean OD's
     * renewal already does, so the CBE Corridor Rate cascade keeps
     * owning that field when the facility follows it.
     *
     * The rate card itself is NOT chapter-versioned (facility_lc_types
     * has never been dated history — a plain Edit already replaces it
     * wholesale the same way), so a renewal replaces it the same way
     * Edit does — this action, not a new lc_types table, IS the audit
     * trail for "what changed and when" (captured explicitly below,
     * since $facility->toArray() doesn't include the lcTypes relation).
     */
    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $lcTypes = $data['lc_types'] ?? [];
            $this->validateLcTypes($lcTypes);

            if ($facility->follows_corridor_rate) {
                unset($data['borrowing_rate']);
            }

            $beforeLcTypes = $facility->lcTypes()->orderBy('sort_order')->get()->toArray();

            // Dated chapter: financing terms + (Limit or CD/TD Lending
            // %, depending on lc_security_type). CreditFacility::renew()
            // also writes its own audit_log row for this part
            // (credit_facility.renewed).
            $facility->renew($data, $actor);

            // Rate card: replaced wholesale with the renewed terms —
            // same replace-and-reseed approach update() already uses.
            $facility->lcTypes()->delete();
            $this->seedLcTypes($companyId, $facility, $lcTypes);

            $afterLcTypes = $facility->fresh('lcTypes')->lcTypes()->orderBy('sort_order')->get()->toArray();

            AuditLog::record(
                $actor,
                'credit_facility.lc_renewed',
                $facility,
                ['lc_types' => $beforeLcTypes],
                ['lc_types' => $afterLcTypes]
            );

            return $facility->fresh(['bank', 'currency', 'lcTypes']);
        });
    }

    /**
     * Undoes the facility's most recent renewal. See
     * CreditFacility::deleteLatestRenewal() for the full rule set —
     * its withdrawal/liability-charge blocking checks will simply
     * never find anything for an LC facility (those tables only ever
     * get populated once an LC drawdown/settlement layer is built),
     * so the only real gate here is "this isn't the Original chapter".
     */
    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency', 'lcTypes']);
    }

    // ── Private: computed fields ──────────────────────────────────

    /**
     * Fully Secured LC: Limit is computed from the pledged TD, not
     * typed in directly — confirmed formula: TD Amount × TD Lending
     * Percentage ÷ 100. Written into the existing facility_limit
     * column (single source of truth), not a separate one.
     * Unsecured LC keeps facility_limit exactly as submitted.
     */
    private function applyComputedLimit(array &$data): void
    {
        if (($data['lc_security_type'] ?? null) !== 'fully_secured') {
            return;
        }

        $data['facility_limit'] = bcdiv(
            bcmul((string) ($data['lc_td_amount'] ?? 0), (string) ($data['lc_td_lending_pct'] ?? 0), 4),
            '100',
            2
        );
    }

    /**
     * Financing Terms & Conditions — identical convention to Clean
     * Overdraft: interest_rate is auto-derived, never typed directly.
     */
    private function applyInterestRate(array &$data): void
    {
        if (isset($data['borrowing_rate']) && isset($data['bank_margin_rate'])) {
            $data['interest_rate'] = bcadd(
                (string) $data['borrowing_rate'],
                (string) $data['bank_margin_rate'],
                4
            );
        }
    }

    // ── Private: validation ───────────────────────────────────────

    private function validateLcTypes(array $lcTypes): void
    {
        if (empty($lcTypes)) {
            throw new \DomainException('At least one LC type is required — the rate card cannot be empty.');
        }

        $seen = [];
        foreach ($lcTypes as $row) {
            $type = strtolower(trim($row['lc_type'] ?? ''));
            if ($type === '') {
                throw new \DomainException('Every LC type row needs a name.');
            }
            if (in_array($type, $seen, true)) {
                throw new \DomainException("\"{$row['lc_type']}\" appears more than once.");
            }
            $seen[] = $type;
        }
    }

    private function seedLcTypes(int $companyId, CreditFacility $facility, array $lcTypes): void
    {
        foreach ($lcTypes as $index => $row) {
            if (!isset($row['lc_type']) || trim($row['lc_type']) === '') {
                continue;
            }

            FacilityLcType::create([
                'company_id'                => $companyId,
                'credit_facility_id'        => $facility->id,
                'lc_type'                   => trim($row['lc_type']),
                'cash_cover_pct'             => $row['cash_cover_pct']             ?? 0,
                'commission_rate_pct'        => $row['commission_rate_pct']        ?? 0,
                'min_commission_fee_amount'  => $row['min_commission_fee_amount']  ?? 0,
                'issuance_fee_amount'        => $row['issuance_fee_amount']        ?? 0,
                'sort_order'                 => $index,
            ]);
        }
    }

    /**
     * Rule 2.5 — currency_id and lc_td_currency_id both come from
     * dropdowns mixing system-shared rows (company_id = NULL) with
     * this company's own — verify both belong to this company.
     */
    private function validateForeignKeys(int $companyId, array $data): void
    {
        foreach (['currency_id', 'lc_td_currency_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $belongsToCompany = Currency::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data[$field])
                ->exists();

            if (! $belongsToCompany) {
                throw new \DomainException('Selected currency is not available for this company.');
            }
        }
    }
}
