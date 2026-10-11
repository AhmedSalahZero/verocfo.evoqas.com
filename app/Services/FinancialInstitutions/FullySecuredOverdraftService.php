<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FullySecuredOverdraftService
//
//  All business logic for Fully Secured Overdraft (223006) facility
//  contracts. Mirrors CleanOverdraftService's shape closely — same
//  OD financing terms, same Outstanding Breakdown pattern, same
//  Facility Renewal mechanics — with one structural difference:
//
//  Layer 1C responsibilities (this service):
//    - Create / update OD facility contract
//    - COMPUTE facility_limit from the pledged Time Deposit:
//        facility_limit = fsod_td_amount × fsod_td_lending_pct ÷ 100
//      Same formula and bcmath pattern already built and confirmed
//      for Letter of Credit's fully-secured variant — see
//      LetterOfCreditService::applyComputedLimit(). Locked in at
//      create/renew time, never recalculated live off the TD's
//      current balance (confirmed with Mahmoud, August 2026) — a
//      renewal is the only thing that ever moves this number.
//    - Seed opening balance withdrawals
//    - Initialize daily balance log from opening balance date
//    - Validate: outstanding_balance <= computed facility_limit
//    - Validate: breakdown total ≈ outstanding_balance (tolerance 0.01)
//    - Auto-calculate and store interest_rate = borrowing + margin
//      (same generic OD financing terms as Clean OD — Fully Secured
//      OD still has its own borrowing_rate/bank_margin_rate/hdb_rate/
//      admin_fee_rate independent of the TD's own interest rate,
//      which is purely informational — see fsod_td_interest_rate).
//
//  Layer 6 responsibilities (deferred — confirmed with Mahmoud,
//  built together for every OD type at once, not per-type):
//    - recordWithdrawal() / recordSettlement() / postMonthlyInterest()
//      / postHdbFee() / updateDailyBalance() — none of this exists
//      yet for ANY OD type in Vero CFO (Clean OD included). Fully
//      Secured OD is being scoped today as setup/CRUD only, same
//      stage every other OD type is currently at.
//
//  Interest formula: daily = balance × (rate / 100) / 360
//  All monetary calculations use bcmath. Never PHP float.
//
//  Note on code duplication: calcInterestRate(), calcDailyInterest(),
//  calcMonthlyTheoreticalInterest(), calcQuarterHdbFee(),
//  initializeDailyBalance(), seedOpeningWithdrawals(), and
//  validateForeignKeys() are near-identical to the same methods in
//  CleanOverdraftService and CommercialPaperOverdraftService.
//  Deliberately duplicated again here, same reasoning as
//  CommercialPaperOverdraftService's own note: with three OD types
//  now sharing this surface, extracting a shared base/trait becomes
//  more tempting, but the real common surface should be settled once
//  and for all when the Settlement Engine is built (Layer 6, all OD
//  types together) — not guessed at piecemeal across three separate
//  setup-only builds.
//
//  @layer    Service
//  @depends  CreditFacility, FacilityWithdrawal, FacilityDailyBalance, AuditLog, BankAccount
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

class FullySecuredOverdraftService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank:id,name_en',
                // Same eager-load bug-fix pattern used everywhere
                // else in this project (see CleanOverdraftService) —
                // the shorthand form re-applies Currency's global
                // CompanyScope and silently drops every system
                // currency.
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                'fsodTdAccount:id,account_name,account_number,td_amount',
                'fsodTdCurrency:id,code',
                'termsHistories',
            ])
            ->withCount([
                'withdrawals',
                'liabilityCharges as posted_liability_charges_count' => fn ($q) => $q->where('status', 'posted'),
            ])
            ->where('company_id', $companyId)
            ->where('facility_type', 'fully_secured')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Portfolio Stats (for index page KPI cards) ──────────────────

    /**
     * Same shape as CleanOverdraftService::stats() — see that
     * method's own comment for why totals are grouped by currency
     * rather than summed across them.
     */
    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'fully_secured')
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

            $this->validateForeignKeys($companyId, $data);

            // Computed limit FIRST — validateOdData() needs the real
            // number to check outstanding_balance against, not
            // whatever (if anything) the client sent as facility_limit.
            $data['facility_limit'] = $this->computeLimit($data);
            $this->validateOdData($data);

            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate'] ?? '0',
                $data['bank_margin_rate'] ?? '0'
            );

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'fully_secured';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';

            $breakdown = $data['breakdown'] ?? [];
            unset($data['breakdown']);

            $facility = CreditFacility::create($data);

            if (!empty($breakdown)) {
                $this->seedOpeningWithdrawals($companyId, $facility, $breakdown);
            }

            // BUG FIX carried over from CleanOverdraftService v1.1:
            // isset(), not empty() — a legitimate zero opening balance
            // must still create the day-one balance row.
            if (!empty($data['balance_date']) && isset($data['outstanding_balance'])) {
                $this->initializeDailyBalance($companyId, $facility);
            }

            // Facility Renewal — every facility gets its "chapter one"
            // row the moment it's created (confirmed with Mahmoud:
            // renewal history included from the start for this
            // facility type).
            $facility->createOriginalTermsHistory();

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'fsodTdAccount', 'fsodTdCurrency']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            $this->validateForeignKeys($companyId, $data);

            $data['facility_limit'] = $this->computeLimit(array_merge(
                ['fsod_td_amount' => $facility->fsod_td_amount, 'fsod_td_lending_pct' => $facility->fsod_td_lending_pct],
                $data
            ));
            $this->validateOdData($data);

            $data['interest_rate'] = $this->calcInterestRate(
                $data['borrowing_rate']   ?? $facility->borrowing_rate,
                $data['bank_margin_rate'] ?? $facility->bank_margin_rate
            );

            $facility->assertOutstandingFollowsMovements($data);

            $before    = $facility->toArray();
            $breakdown = $data['breakdown'] ?? [];
            unset($data['breakdown']);

            $facility->update($data);

            if (!empty($breakdown)) {
                $facility->withdrawals()->where('is_opening_balance', true)->delete();
                $this->seedOpeningWithdrawals($companyId, $facility, $breakdown);
            }

            // Facility Renewal — Edit corrects the currently-live
            // chapter in place, same rule as every other OD type.
            $facility->syncLatestTermsChapter();

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'fsodTdAccount', 'fsodTdCurrency']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    /**
     * Records a renewal. See CreditFacility::renew() for the full
     * validation/carry-forward rules — including how it recomputes
     * facility_limit from whichever fsod_td_amount/fsod_td_lending_pct
     * pairing ends up live in the new chapter, rather than trusting a
     * raw limit number.
     */
    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        if ($facility->follows_corridor_rate) {
            unset($data['borrowing_rate']);
        }

        $facility->renew($data, $actor);

        return $facility->fresh(['bank', 'currency', 'fsodTdAccount', 'fsodTdCurrency']);
    }

    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency', 'fsodTdAccount', 'fsodTdCurrency']);
    }

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

    // ── Computed Limit ───────────────────────────────────────────

    /**
     * facility_limit = fsod_td_amount × fsod_td_lending_pct ÷ 100.
     * Identical formula and precision to
     * LetterOfCreditService::applyComputedLimit() — 4dp intermediate
     * multiplication, 2dp final result (money never carries more
     * than 2dp once it's a stored, user-facing amount).
     */
    private function computeLimit(array $data): string
    {
        return bcdiv(
            bcmul((string) ($data['fsod_td_amount'] ?? 0), (string) ($data['fsod_td_lending_pct'] ?? 0), 4),
            '100',
            2
        );
    }

    // ── Interest Rate Calculation ─────────────────────────────────

    public function calcInterestRate(string $borrowingRate, string $bankMarginRate): string
    {
        return bcadd((string) $borrowingRate, (string) $bankMarginRate, 4);
    }

    // ── Daily Interest Calculation (360-day Egyptian standard) ────

    public function calcDailyInterest(string $balance, string $annualRate): string
    {
        $dailyRate = bcdiv($annualRate, '100', 10);
        $annualInterest = bcmul($balance, $dailyRate, 10);

        return bcdiv($annualInterest, '360', 6);
    }

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
                "Outstanding balance ({$balance}) cannot exceed the computed facility limit ({$limit}) — check the linked TD amount and lending percentage."
            );
        }

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
                continue;
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
     * Rule 2.5 — Cross-company FK validation. Same checks as
     * CleanOverdraftService, plus fsod_td_account_id — the pledged TD
     * must actually belong to this company (bank_accounts is
     * company-scoped for every row; unlike currencies/COA there is no
     * "system-shared, company_id = NULL" case to worry about here,
     * but the explicit check still closes the same class of gap the
     * controller's exists:table,id rule leaves open).
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

        if (!empty($data['fsod_td_currency_id'])) {
            $belongsToCompany = Currency::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data['fsod_td_currency_id'])
                ->exists();

            if (! $belongsToCompany) {
                throw new \DomainException('Selected TD currency is not available for this company.');
            }
        }

        if (!empty($data['fsod_td_account_id'])) {
            $belongsToCompany = DB::table('bank_accounts')
                ->where('company_id', $companyId)
                ->where('id', $data['fsod_td_account_id'])
                ->exists();

            if (! $belongsToCompany) {
                throw new \DomainException('Selected Time Deposit account is not available for this company.');
            }
        }
    }
}
