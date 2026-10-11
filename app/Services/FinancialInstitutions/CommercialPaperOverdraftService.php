<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CommercialPaperOverdraftService
//
//  All business logic for OD Against Commercial Paper (223002)
//  facility contracts.
//
//  Layer 1C responsibilities (this service, today):
//    - Create / update OD facility contract
//    - Create / replace the lending-tier rate card
//    - Initialize daily balance log from opening balance date
//    - Validate: outstanding_balance <= facility_limit
//    - Validate: no two tiers share the same due_within_days
//    - Auto-calculate and store interest_rate = borrowing + margin
//
//  Deliberately NOT in this service (by design, confirmed with
//  the business owner before building):
//    - No cheque tracking. This facility does not record actual
//      commercial papers deposited for collection — that lives in
//      a later "money transaction" feature. Cheques are entered
//      and marked collected manually elsewhere; this app never
//      auto-collects anything.
//    - No "Outstanding Breakdown" tranches (unlike Clean
//      Overdraft) — there is nothing to break the opening balance
//      into here, since cheque-level detail isn't tracked by this
//      form. outstanding_balance is a single opening number.
//    - The facility's REAL usable capacity (sum of each
//      cheque's face value × its tier's lending rate) is not
//      computed here — it can't be, with no cheques tracked yet.
//      facility_limit stays a simple bank-approved ceiling check,
//      same as Clean Overdraft, until cheque tracking exists.
//
//  Layer 6 responsibilities (added later, once cheque tracking
//  exists):
//    - recordChequeDeposit()  — matches a cheque to a tier, posts
//                                its lending value as a drawdown
//    - recordChequeCollected() — manual entry, not automatic
//    - postMonthlyInterest() / postHdbFee() / updateDailyBalance()
//      — same as Clean Overdraft, once that engine is built
//
//  Interest formula: daily = balance × (rate / 100) / 360
//  All monetary calculations use bcmath. Never PHP float.
//
//  Note on code duplication: calcInterestRate(), calcDailyInterest(),
//  calcQuarterHdbFee(), initializeDailyBalance(), and
//  validateForeignKeys() are near-identical to the same methods in
//  CleanOverdraftService. Deliberately duplicated rather than
//  shared for this first round — with only two facility types
//  built, it's safer to see the real common surface across 2-3
//  types before extracting a shared base/trait, rather than guess
//  at the right abstraction now and risk destabilizing the
//  already-working Clean Overdraft service.
//
//  v1.1 — Facility Renewal (see CreditFacility's "Facility Renewal"
//    section). create() now seeds the facility's Original chapter
//    and tags its lending tiers to that chapter. update() now syncs
//    the currently-live chapter's numbers in place and only replaces
//    THAT chapter's tiers — never touching an earlier, frozen
//    chapter's rate card. renewFacility(), deleteRenewal(), and
//    delete() added — renewal always requires a whole new tier set
//    (see RenewCommercialPaperOverdraftRequest), tagged to the newly
//    created chapter.
//
//  @layer    Service
//  @depends  CreditFacility, CommercialPaperLendingTier, FacilityDailyBalance, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FinancialInstitutions\CommercialPaperLendingTier;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CommercialPaperOverdraftService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank:id,name_en',
                // Eager-loaded through a closure with withoutGlobalScopes()
                // — the shorthand 'currency:id,code' re-applies Currency's
                // global CompanyScope and silently drops every system
                // currency (company_id = NULL), i.e. almost all of them.
                // Same root cause/fix as CleanOverdraftService::paginate().
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                // Facility Renewal — chapter timeline for the Renew
                // modal and the renewal-count badge.
                'termsHistories',
            ])
            // Cheap counts (not full row loads) so the Vue page can
            // compute "can this be deleted outright" client-side,
            // matching CreditFacility::hasAnyTransactions().
            ->withCount([
                'withdrawals',
                'liabilityCharges as posted_liability_charges_count' => fn ($q) => $q->where('status', 'posted'),
            ])
            ->where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'commercial_paper')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Portfolio Stats (for index page KPI cards) ──────────────────

    /**
     * Portfolio-wide aggregates for the index page. Same currency
     * safety rules as CleanOverdraftService::stats() — see that
     * file for the full reasoning. Totals are grouped by currency,
     * never summed across currencies; utilization is averaged as
     * a per-facility ratio.
     */
    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'commercial_paper')
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
                DB::raw('SUM(credit_facilities.outstanding_balance) as total_outstanding'),
            ])
            ->orderBy('currencies.code')
            ->get();

        $rows = $base()
            ->where('facility_limit', '>', 0)
            ->get(['facility_limit', 'outstanding_balance']);

        $utilizationSum = '0';
        $nearLimitCount = 0;

        foreach ($rows as $row) {
            $pct = bcmul(
                bcdiv((string) $row->outstanding_balance, (string) $row->facility_limit, 6),
                '100',
                6
            );
            $utilizationSum = bcadd($utilizationSum, $pct, 6);
            if (bccomp($pct, '85', 2) >= 0) {
                $nearLimitCount++;
            }
        }

        $avgUtilization = $rows->isEmpty()
            ? '0.00'
            : bcdiv($utilizationSum, (string) $rows->count(), 2);

        return [
            'total_count'      => $totalCount,
            'active_count'     => $activeCount,
            'by_currency'      => $byCurrency,
            'avg_utilization'  => $avgUtilization,
            'near_limit_count' => $nearLimitCount,
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $this->validateOdData($data);
            $this->validateForeignKeys($companyId, $data);

            $lendingTiers = $data['lending_tiers'] ?? [];
            $this->validateLendingTiers($lendingTiers);

            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate'] ?? '0',
                $data['bank_margin_rate'] ?? '0'
            );

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'commercial_paper';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';

            unset($data['lending_tiers']);

            $facility = CreditFacility::create($data);

            // Facility Renewal — every facility gets its "chapter one"
            // row the moment it's created.
            $chapter = $facility->createOriginalTermsHistory();

            $this->seedLendingTiers($companyId, $facility, $chapter->id, $lendingTiers);

            // Initialize daily balance log from balance_date. isset(), not
            // empty() — a zero opening balance is legitimate (a freshly
            // opened, undrawn facility) and must not be skipped. Same fix
            // as CleanOverdraftService::create().
            if (!empty($data['balance_date']) && isset($data['outstanding_balance'])) {
                $this->initializeDailyBalance($companyId, $facility);
            }

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'lendingTiers']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $this->validateOdData($data);
            $this->validateForeignKeys($companyId, $data);

            $lendingTiers = $data['lending_tiers'] ?? [];
            $this->validateLendingTiers($lendingTiers);

            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate']   ?? $facility->borrowing_rate,
                $data['bank_margin_rate'] ?? $facility->bank_margin_rate
            );

            $facility->assertOutstandingFollowsMovements($data);

            $before = $facility->toArray();

            unset($data['lending_tiers']);

            $facility->update($data);

            // Facility Renewal — Edit always corrects whichever chapter
            // is CURRENTLY live, in place (never locked by a renewal
            // having happened — confirmed with Mahmoud).
            $facility->syncLatestTermsChapter();

            // Replace only the CURRENT chapter's tiers — an earlier,
            // frozen chapter's rate card (if this facility has been
            // renewed) is never touched by a plain Edit.
            $currentChapterId = $facility->getLatestTerms()?->id;
            $facility->lendingTiers()->where('credit_facility_terms_history_id', $currentChapterId)->delete();
            $this->seedLendingTiers($companyId, $facility, $currentChapterId, $lendingTiers);

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'lendingTiers']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * Records a renewal — a brand-new dated chapter, PLUS a whole new
     * lending-tier rate card tagged to it (required, never optional —
     * an empty schedule would mean every cheque deposited after this
     * renewal silently gets a zero rate). The old chapter's tiers are
     * left completely untouched. borrowing_rate is stripped whenever
     * the facility follows the CBE Corridor Rate, same rule as Clean OD.
     */
    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        if ($facility->follows_corridor_rate) {
            unset($data['borrowing_rate']);
        }

        $tiers = $data['tiers'] ?? [];
        $this->validateLendingTiers($tiers);
        unset($data['tiers']);

        $chapter = $facility->renew($data, $actor);

        $this->seedLendingTiers($companyId, $facility, $chapter->id, $tiers);

        return $facility->fresh(['bank', 'currency', 'lendingTiers']);
    }

    /**
     * Undoes the facility's most recent renewal. Its tier rows
     * cascade-delete with the chapter (FK onDelete cascade) — see
     * migration 2026_01_01_000135.
     */
    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency', 'lendingTiers']);
    }

    /**
     * Deletes the whole facility outright. Only ever allowed while
     * still on its Original chapter, and only if no real money has
     * moved through it.
     */
    public function delete(int $companyId, CreditFacility $facility, User $actor): void
    {
        if (! $facility->canBeDeleted()) {
            throw new \DomainException(
                $facility->hasRenewals()
                    ? 'This facility has been renewed and cannot be deleted directly. Delete its most recent renewal first to bring it back to its original terms.'
                    : 'This facility cannot be deleted because it already has real transactions recorded against it.'
            );
        }

        $before = $facility->toArray();

        $facility->delete();

        AuditLog::record($actor, 'credit_facility.deleted', $facility, $before, null);
    }

    // ── Interest Rate Calculation ─────────────────────────────────

    /**
     * Egyptian banking: interest_rate = borrowing_rate + bank_margin_rate
     * Stored to 4dp. Uses bcmath.
     */
    public function calcInterestRate(string $borrowingRate, string $bankMarginRate): string
    {
        return bcadd(
            (string) $borrowingRate,
            (string) $bankMarginRate,
            4
        );
    }

    // ── Daily Interest Calculation (360-day Egyptian standard) ────

    /**
     * Daily interest = balance × (annual_rate / 100) / 360
     * Uses bcmath throughout — never PHP float.
     */
    public function calcDailyInterest(string $balance, string $annualRate): string
    {
        $dailyRate      = bcdiv($annualRate, '100', 10);
        $annualInterest = bcmul($balance, $dailyRate, 10);

        return bcdiv($annualInterest, '360', 6);
    }

    /**
     * Quarter HDB fee = highest closing_balance in quarter × (hdb_rate / 100)
     */
    public function calcQuarterHdbFee(int $companyId, CreditFacility $facility, int $year, int $quarter): string
    {
        $peak = FacilityDailyBalance::where('company_id', $companyId)
            ->where('credit_facility_id', $facility->id)
            ->forQuarter($year, $quarter)
            ->max('closing_balance');

        if (!$peak) return '0.00';

        return bcmul(
            (string) $peak,
            bcdiv((string) $facility->hdb_rate, '100', 10),
            2
        );
    }

    // ── Private Helpers ───────────────────────────────────────────

    private function validateOdData(array $data): void
    {
        $limit   = (string) ($data['facility_limit']      ?? 0);
        $balance = (string) ($data['outstanding_balance'] ?? 0);

        if (bccomp($balance, $limit, 2) > 0) {
            throw new \DomainException(
                "Outstanding balance ({$balance}) cannot exceed facility limit ({$limit})."
            );
        }
    }

    /**
     * Each day-bracket can only have one rate. The database also
     * enforces this (unique on credit_facility_id + due_within_days)
     * — this check exists so the person sees a clear message instead
     * of a raw "duplicate entry" database error.
     */
    private function validateLendingTiers(array $tiers): void
    {
        $seenDays = [];

        foreach ($tiers as $tier) {
            $days = (int) ($tier['due_within_days'] ?? 0);

            if (in_array($days, $seenDays, true)) {
                throw new \DomainException(
                    "Two lending tiers both say \"due within {$days} days\" — each day bracket can only have one rate."
                );
            }

            $seenDays[] = $days;
        }
    }

    private function seedLendingTiers(int $companyId, CreditFacility $facility, ?int $termsHistoryId, array $tiers): void
    {
        foreach ($tiers as $tier) {
            if (!isset($tier['due_within_days']) || !isset($tier['lending_rate_pct'])) {
                continue; // skip incomplete rows
            }

            CommercialPaperLendingTier::create([
                'company_id'                        => $companyId,
                'credit_facility_id'                 => $facility->id,
                'credit_facility_terms_history_id'   => $termsHistoryId,
                'due_within_days'                    => $tier['due_within_days'],
                'lending_rate_pct'                    => $tier['lending_rate_pct'],
            ]);
        }
    }

    private function initializeDailyBalance(int $companyId, CreditFacility $facility): void
    {
        $annualRate = (string) ($facility->interest_rate ?? 0);

        FacilityDailyBalance::updateOrCreate(
            [
                'company_id'         => $companyId,
                'credit_facility_id' => $facility->id,
                'balance_date'       => $facility->balance_date,
            ],
            [
                'opening_balance'       => '0.00',
                'withdrawals_today'     => $facility->outstanding_balance,
                'charges_today'         => '0.00',
                'settlements_today'     => '0.00',
                'closing_balance'       => $facility->outstanding_balance,
                'daily_interest_amount' => $this->calcDailyInterest(
                    (string) $facility->outstanding_balance,
                    $annualRate
                ),
                'is_quarter_peak'       => false,
            ]
        );
    }

    /**
     * Rule 2.5 — Cross-company FK validation. Same as
     * CleanOverdraftService::validateForeignKeys() — coa_account_id
     * and currency_id come from dropdowns mixing system-shared rows
     * (company_id = NULL) with this company's own rows; the
     * controller's exists:table,id rule only confirms the row
     * exists somewhere, not that it's visible to this company.
     */
    private function validateForeignKeys(int $companyId, array $data): void
    {
        if (!empty($data['coa_account_id'])) {
            $belongsToCompany = ChartOfAccount::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data['coa_account_id'])
                ->exists();

            if (! $belongsToCompany) {
                throw new \DomainException('Selected COA account is not available for this company.');
            }
        }

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
