<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CleanOverdraftService
//
//  All business logic for Clean Overdraft (223001) facility contracts.
//
//  Layer 1C responsibilities (this service):
//    - Create / update OD facility contract
//    - Seed opening balance withdrawals
//    - Initialize daily balance log from opening balance date
//    - Validate: outstanding_balance <= facility_limit
//    - Validate: breakdown total ≈ outstanding_balance (tolerance 0.01)
//    - Auto-calculate and store interest_rate = borrowing + margin
//
//  Layer 6 responsibilities (added later — settlement engine):
//    - recordWithdrawal()    — called by supplier payment posting
//    - recordSettlement()    — called by any inflow to OD account
//    - postMonthlyInterest() — called by month-end closing
//    - postHdbFee()          — called by quarter-end closing
//    - updateDailyBalance()  — called after every transaction
//
//  Interest formula: daily = balance × (rate / 100) / 360
//  All monetary calculations use bcmath. Never PHP float.
//
//  Full audit fixes applied (v1.1):
//    1. resolveCoaAccount() queried a non-existent column
//       'account_code' — actual column is 'code'. Caused an
//       immediate QueryException on every create().
//    2. create() was overwriting the user's validated
//       coa_account_id selection with resolveCoaAccount()'s
//       result — silently discarding the COA account the user
//       actually picked in the form. update() never did this;
//       that inconsistency was the tell that it was dead/broken
//       code, not intended behavior. Removed — create() and
//       update() now both trust the validated coa_account_id.
//    3. resolveCoaAccount() also had no ORDER BY, so if more
//       than one account code matched, which one came back was
//       undefined. Moot now that the method itself is gone,
//       replaced by validateForeignKeys() (see #4).
//    4. Added validateForeignKeys() — Rule 2.5 cross-company FK
//       check. Nothing previously confirmed coa_account_id /
//       currency_id actually belong to (or are system-shared
//       with) this company before saving; the controller's
//       exists:table,id rule only confirms the row exists
//       somewhere, not that it's this company's.
//    5. create()'s daily-balance initialization check used
//       !empty($data['outstanding_balance']) — but empty(0) and
//       empty('0') are both true in PHP, so a legitimate zero
//       opening balance silently skipped creating the day-one
//       balance row. Changed to isset().
//    6. paginate() accepted $companyId but never used it —
//       worked only because CreditFacility's global scope
//       happened to filter by company anyway. Now applied
//       explicitly so the parameter isn't a silent no-op.
//
//  Added in v1.2:
//    - stats() — portfolio-wide KPI aggregates for the new
//      CleanOverdraftIndex.vue page. Totals are grouped by
//      currency (never summed across currencies — a company can
//      hold OD facilities in more than one). Average utilization
//      and the near-limit count are computed from each facility's
//      own ratio using bcmath, not from summed amounts.
//
//  v1.2.1 — stats()'s by_currency query joins the currencies
//    table, which also has its own company_id column (it's
//    nullable, for shared system currencies — see Currency
//    model). That made the unqualified where('company_id', ...)
//    ambiguous to MySQL once the join was added. Qualified it as
//    credit_facilities.company_id.
//
//  v1.3 — paginate() eager-loaded currency via the shorthand
//    'currency:id,code', which silently re-applies Currency's
//    global CompanyScope and drops every system currency (i.e.
//    almost all of them) from the relation. Same fix pattern as
//    everywhere else this bug has shown up this project: load
//    the relation through a closure that calls
//    withoutGlobalScopes(). Also fixed the identical bug in
//    BankService::paginateFacilities() and
//    BankController::accountsIndex(), which had the exact same
//    shorthand eager-load.
//
//  v1.4 — Facility Renewal (see CreditFacility's "Facility Renewal"
//    section, migration 2026_01_01_000134). create() now also seeds
//    the facility's Original terms-history chapter. update() now
//    also syncs the currently-live chapter's numbers in place
//    (Edit is never locked by renewal — confirmed with Mahmoud).
//    renewFacility(), deleteRenewal(), and delete() added.
//
//  @layer    Service
//  @depends  CreditFacility, FacilityWithdrawal, FacilityDailyBalance, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CleanOverdraftService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank:id,name_en',
                // BUG FIX: the shorthand 'currency:id,code' eager-loads via
                // Currency's own query, which re-applies the global
                // CompanyScope — silently dropping every system currency
                // (company_id = NULL), which is almost all of them. This
                // is the same root cause as the Currency/COA dropdown bugs
                // we fixed earlier, just showing up through Eloquent's
                // automatic relationship loading instead of a manual query.
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                // Facility Renewal — chapter timeline, for the Renew
                // modal and the renewal-count badge on the index row.
                'termsHistories',
            ])
            // Facility Renewal — cheap counts (not full row loads) so
            // the Vue page can compute "can this be deleted outright"
            // client-side without an extra request per row. Matches
            // CreditFacility::hasAnyTransactions()'s own definition.
            ->withCount([
                'withdrawals',
                'liabilityCharges as posted_liability_charges_count' => fn ($q) => $q->where('status', 'posted'),
            ])
            ->where('company_id', $companyId)
            ->where('facility_type', 'clean_od')
            ->when($filters['bank_id']  ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']   ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Portfolio Stats (for index page KPI cards) ──────────────────

    /**
     * Portfolio-wide aggregates for the Clean Overdraft index page.
     * Respects the same filters as paginate() so the KPI row always
     * matches what's actually listed below it.
     *
     * IMPORTANT: facility_limit / outstanding_balance are NEVER summed
     * across currencies — a company can hold OD facilities in EGP and
     * USD at once, and adding those raw numbers together would produce
     * a number with no real meaning. Totals are grouped by currency;
     * utilization is averaged as a per-facility ratio (which is
     * already currency-neutral) rather than from summed amounts.
     */
    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'clean_od')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s));

        $totalCount  = $base()->count();
        $activeCount = $base()->where('status', 'active')->count();

        // Totals per currency — safe to display side by side, never combined.
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

        // Average utilization + near-limit count, computed together from
        // each facility's own ratio (bcmath throughout). Facilities with
        // a zero limit are excluded — utilization is undefined for them,
        // not zero, so including them would distort the average.
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

            // Auto-calculate interest rate
            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate'] ?? '0',
                $data['bank_margin_rate'] ?? '0'
            );

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'clean_od';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';

            $breakdown = $data['breakdown'] ?? [];
            unset($data['breakdown']);

            $facility = CreditFacility::create($data);

            // Seed opening balance withdrawals
            if (!empty($breakdown)) {
                $this->seedOpeningWithdrawals($companyId, $facility, $breakdown);
            }

            // Initialize daily balance log from balance_date.
            // BUG FIX: outstanding_balance = 0 is a legitimate value
            // (a freshly opened, undrawn facility) — empty('0') and
            // empty(0) are both true in PHP, so the old check here
            // silently skipped this step for exactly that case.
            // isset() checks presence, not truthiness.
            if (!empty($data['balance_date']) && isset($data['outstanding_balance'])) {
                $this->initializeDailyBalance($companyId, $facility);
            }

            // Facility Renewal — every facility gets its "chapter one"
            // row the moment it's created, so hasRenewals()/renew()
            // always have a baseline to work from.
            $facility->createOriginalTermsHistory();

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $this->validateOdData($data);
            $this->validateForeignKeys($companyId, $data);

            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate']   ?? $facility->borrowing_rate,
                $data['bank_margin_rate'] ?? $facility->bank_margin_rate
            );

            $facility->assertOutstandingFollowsMovements($data);

            $before    = $facility->toArray();
            $breakdown = $data['breakdown'] ?? [];
            unset($data['breakdown']);

            $facility->update($data);

            // Re-seed opening withdrawals if breakdown changed
            if (!empty($breakdown)) {
                // Only replace opening balance rows — preserve live withdrawals
                $facility->withdrawals()->where('is_opening_balance', true)->delete();
                $this->seedOpeningWithdrawals($companyId, $facility, $breakdown);
            }

            // Facility Renewal — Edit always corrects whichever chapter
            // is CURRENTLY live, in place (never locked by a renewal
            // having happened — confirmed with Mahmoud). This never
            // creates a new chapter or touches effective_date; only
            // renew() does that.
            $facility->syncLatestTermsChapter();

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * Records a renewal — a brand-new dated chapter of terms. See
     * CreditFacility::renew() for the full validation/carry-forward
     * rules. borrowing_rate is stripped from $data before it ever
     * reaches the model whenever the facility follows the CBE
     * Corridor Rate — that field stays owned by the corridor cascade
     * in that case, never by a manually-typed renewal value (confirmed
     * with Mahmoud).
     */
    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        if ($facility->follows_corridor_rate) {
            unset($data['borrowing_rate']);
        }

        $facility->renew($data, $actor);

        return $facility->fresh(['bank', 'currency']);
    }

    /**
     * Undoes the facility's most recent renewal. See
     * CreditFacility::deleteLatestRenewal() for the full rule set.
     */
    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency']);
    }

    /**
     * Deletes the whole facility outright. Only ever allowed while
     * it's still on its Original chapter (never renewed) and only if
     * no real money has moved through it — see
     * CreditFacility::canBeDeleted(). Renewal chapters cascade-delete
     * with it (FK onDelete cascade) since they're meaningless without
     * their parent facility.
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
     * Returns 6dp precision before display rounding.
     */
    public function calcDailyInterest(string $balance, string $annualRate): string
    {
        // Step 1: rate / 100
        $dailyRate = bcdiv($annualRate, '100', 10);

        // Step 2: balance × dailyRate
        $annualInterest = bcmul($balance, $dailyRate, 10);

        // Step 3: ÷ 360 (Egyptian banking convention — not 365)
        return bcdiv($annualInterest, '360', 6);
    }

    /**
     * Monthly theoretical interest = sum of all daily interest amounts for the month.
     * Used for month-end posting suggestion (user verifies against bank statement).
     */
    public function calcMonthlyTheoreticalInterest(int $companyId, CreditFacility $facility, int $year, int $month): string
    {
        $rows = FacilityDailyBalance::where('company_id', $companyId)
            ->where('credit_facility_id', $facility->id)
            ->forMonth($year, $month)
            ->get(['daily_interest_amount']);

        return $rows->reduce(
            fn ($carry, $row) => bcadd($carry, (string) $row->daily_interest_amount, 6),
            '0'
        );
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

        // Outstanding balance cannot exceed limit
        if (bccomp($balance, $limit, 2) > 0) {
            throw new \DomainException(
                "Outstanding balance ({$balance}) cannot exceed facility limit ({$limit})."
            );
        }

        // Validate breakdown total ≈ outstanding balance (tolerance 0.01)
        if (!empty($data['breakdown'])) {
            $total = array_reduce(
                $data['breakdown'],
                fn ($carry, $row) => bcadd($carry, (string) ($row['amount'] ?? 0), 2),
                '0'
            );
            $diff = bcsub($total, $balance, 2);
            if (bccomp(ltrim($diff, '-'), '0.01', 2) > 0) {
                throw new \DomainException(
                    "Outstanding breakdown total ({$total}) differs from outstanding balance ({$balance}) by more than 0.01."
                );
            }
        }
    }

    private function seedOpeningWithdrawals(int $companyId, CreditFacility $facility, array $breakdown): void
    {
        foreach ($breakdown as $row) {
            if (empty($row['amount']) || bccomp((string) $row['amount'], '0', 2) <= 0) {
                continue; // skip empty rows
            }

            FacilityWithdrawal::create([
                'company_id'          => $companyId,
                'credit_facility_id'  => $facility->id,
                'withdrawal_date'     => $facility->balance_date,
                'due_date'            => $row['due_date'],
                'amount'              => $row['amount'],
                'settled_amount'      => '0.00',
                'remaining_amount'    => $row['amount'],
                'status'              => 'open',
                'is_opening_balance'  => true,
                'description'         => 'Opening balance withdrawal',
            ]);
        }
    }

    private function initializeDailyBalance(int $companyId, CreditFacility $facility): void
    {
        // Create the initial daily balance row on the balance_date
        // Closing balance = outstanding_balance (the opening position)
        $annualRate = (string) ($facility->interest_rate ?? 0);

        FacilityDailyBalance::updateOrCreate(
            [
                'company_id'          => $companyId,
                'credit_facility_id'  => $facility->id,
                'balance_date'        => $facility->balance_date,
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
     * Rule 2.5 — Cross-company FK validation.
     * coa_account_id and currency_id are picked by the user from
     * dropdowns that mix system-shared rows (company_id = NULL)
     * with this company's own rows. The controller's
     * exists:table,id validation only confirms the row exists
     * SOMEWHERE — not that it's actually visible to this company.
     * This closes that gap before the facility is saved.
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