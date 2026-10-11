<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeaseAmortizationService
//
//  PIECE 1 of the Lease Amortization Engine (see conversation with
//  Mahmoud, July 2026 — full 6-piece scope covers Operating Lease
//  Treatment B and Capital Lease, both of which need this exact same
//  calculation; only the destination account differs, decided later
//  in Piece 2/3/4).
//
//  This class does ONE thing: turns a lease's terms into a full
//  month-by-month (or quarter/half-year/year-ly) payment schedule,
//  each payment split into interest and principal. It does NOT save
//  anything to the database and does NOT post anything to the ledger
//  — that's Piece 2 (activation) and Piece 3 (monthly upkeep). This
//  is deliberately a pure, standalone calculator so it can be
//  reviewed and trusted on its own before anything is wired to it.
//
//  Two starting points, confirmed with Mahmoud:
//    'new'      — a lease starting today. The starting balance
//                  (initial_rou_value / initial_lease_liability) is
//                  CALCULATED here as the present value of every
//                  future payment, discounted at the contract's
//                  borrowing rate.
//    'existing' — a lease that predates Vero CFO (same idea as
//                  Opening Balances elsewhere in the app). Nothing
//                  is calculated from the original signing date —
//                  the user types in where things stand today
//                  (remaining balance + remaining number of
//                  payments), and the schedule is built forward
//                  from THAT, using the exact same amortization
//                  math as the 'new' case.
//
//  Both paths end up calling the same core engine
//  (buildScheduleFromBalance) — 'new' just has one extra step first
//  (presentValue) to work out what that starting balance should be.
//
//  All money math uses bcmath (Building Bible Rule 6) — no floats
//  anywhere in this file.
//
//  AUDIT C8 FIX (Sept 2026) — a lease in USD/EUR had every amount
//  posted 1:1 into the EGP ledger. Now (FxService):
//    • activation, asset wear-down and final purchase — the asset
//      side (ROU / leased asset) is non-monetary, so these use the
//      lease's HISTORICAL rate: the rate typed on the contract, or the
//      rate on its start date.
//    • each lease payment — interest, principal and the bank payment
//      are converted at the rate on the payment date; the bank credit
//      is the sum of the two converted halves, so the entry always
//      balances. (Revaluing the remaining 213 balance at month-end is
//      a separate audit item.)
//  Schedule rows and wear-down rows keep their lease-currency figures.
//
//  AUDIT M5 FIX (Sept 2026) — every lease action now checks its
//  inputs before touching the ledger:
//    • activate()            — contract terms must be complete and
//                              sensible (amount > 0, rate ≥ 0, a first
//                              payment date, at least one installment);
//                              re-checked under a row lock so a double
//                              click cannot build the schedule twice.
//    • recordPayment()       — lease must be active; payment date may
//                              not be in the future or before the lease
//                              start date; row lock stops double posting.
//    • postMonthlyWearDown() — date may not be in the future or before
//                              the lease start; never two wear-downs in
//                              the same installment period; row lock.
//    • triggerFinalPurchase() — value must be a positive 2-decimal
//                              amount; date checked as above; row lock.
//  The HTTP Form Requests check format; these checks are the final
//  word, so any other caller (commands, jobs) is protected too.
//
//  @layer    Service
//  @depends  none (pure calculation — takes plain values in, returns
//            a plain array out; LeasingContract itself is read by
//            the caller, not by this class, to keep it testable
//            in isolation)
// ══════════════════════════════════════════════════════════════════

//  ══════════════════════════════════════════════════════════════════
//  PIECE 2 (activate), PIECE 3 (recordPayment / wear-down / due
//  notifications), PIECE 4 (liabilitySplitAsOf), PIECE 5
//  (triggerFinalPurchase) were added below the original Piece 1
//  calculation methods — see each method's own comment for details.
//  All confirmed with Mahmoud, July 2026:
//
//  - Payments are NEVER posted automatically. The system only
//    notifies; the user records each payment themselves, whenever
//    they actually pay it (late or on time, their call) — automatic
//    posting here could misstate the real bank balance.
//  - The asset wear-down (ROU amortization / Capital Lease
//    depreciation) DOES post automatically each period, since it
//    never touches cash — but it's adjustable: if it ever needs
//    correcting, the user reverses it and posts the right version,
//    same reverse-then-repost pattern JournalEntryService already
//    uses everywhere else. Never a silent edit.
//  - Capital Lease has nothing to do at end of lease — the asset was
//    already recognized as owned from day one. Only Operating Lease
//    Treatment B has a final, user-triggered purchase action.
//
//  CAPITAL LEASE DEPRECIATION — STAND-IN, NOT THE REAL ENGINE:
//  there is no general Fixed Assets & Depreciation module in this
//  codebase yet (0% built, per the current status report). Capital
//  Lease assets are supposed to depreciate "the same way any
//  purchased asset does" — but there's no such mechanism to hook
//  into. postMonthlyWearDown() below includes a simple, self-
//  contained straight-line depreciation calculation scoped ONLY to
//  lease-originated Capital Lease assets, just so Capital Lease is
//  usable today. When the real Fixed Assets & Depreciation module
//  gets built, this should be merged into it rather than left as a
//  second, separate depreciation mechanism.
//  ══════════════════════════════════════════════════════════════════

use App\Exceptions\FinancialInstitutions\LeaseAlreadyActivatedException;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\LeaseAmortizationLine;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Models\FinancialInstitutions\LeaseWearDownEntry;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaseAmortizationService
{
    /** Internal working precision — final money values are rounded to 2dp on the way out. */
    private const SCALE = 10;

    /** How many of these periods fit in a year — drives both the periodic rate and the payment date step. */
    private const PERIODS_PER_YEAR = [
        'monthly'     => 12,
        'quarterly'   => 4,
        'semi_annual' => 2,
        'annual'      => 1,
    ];

    /**
     * Builds the full schedule for a brand-new lease starting today.
     *
     * @param  string  $installmentAmount   The fixed payment each period (e.g. "5000.00")
     * @param  string  $annualRatePercent    The contract's borrowing rate, as a percent (e.g. "12.5" for 12.5%)
     * @param  int     $numberOfInstallments Total number of payments over the life of the lease
     * @param  string  $interval             One of: monthly, quarterly, semi_annual, annual
     * @param  \DateTimeInterface $firstPaymentDate
     *
     * @return array{
     *     initial_value: string,
     *     rows: array<int, array{
     *         period_number: int,
     *         payment_date: string,
     *         payment_amount: string,
     *         principal_amount: string,
     *         interest_amount: string,
     *         balance_after: string,
     *     }>
     * }
     */
    public function calculateForNewLease(
        string $installmentAmount,
        string $annualRatePercent,
        int $numberOfInstallments,
        string $interval,
        \DateTimeInterface $firstPaymentDate,
    ): array {
        $periodicRate = $this->periodicRate($annualRatePercent, $interval);

        $initialValue = $this->presentValue($installmentAmount, $periodicRate, $numberOfInstallments);

        $rows = $this->buildScheduleFromBalance(
            startingBalance: $initialValue,
            installmentAmount: $installmentAmount,
            periodicRate: $periodicRate,
            numberOfInstallments: $numberOfInstallments,
            interval: $interval,
            firstPaymentDate: $firstPaymentDate,
        );

        return ['initial_value' => $initialValue, 'rows' => $rows];
    }

    /**
     * Builds the remaining schedule for a lease that already existed
     * before Vero CFO — starts from whatever the user says the
     * balance is today, not from a calculated present value.
     *
     * @param  string  $openingBalanceToday   What the user says is still owed, as of today
     * @param  string  $installmentAmount
     * @param  string  $annualRatePercent
     * @param  int     $remainingInstallments  How many payments are left, from today
     * @param  string  $interval
     * @param  \DateTimeInterface $nextPaymentDate
     */
    public function calculateForExistingLease(
        string $openingBalanceToday,
        string $installmentAmount,
        string $annualRatePercent,
        int $remainingInstallments,
        string $interval,
        \DateTimeInterface $nextPaymentDate,
    ): array {
        $periodicRate = $this->periodicRate($annualRatePercent, $interval);

        $rows = $this->buildScheduleFromBalance(
            startingBalance: $openingBalanceToday,
            installmentAmount: $installmentAmount,
            periodicRate: $periodicRate,
            numberOfInstallments: $remainingInstallments,
            interval: $interval,
            firstPaymentDate: $nextPaymentDate,
        );

        return ['initial_value' => $openingBalanceToday, 'rows' => $rows];
    }

    // ── Core engine — shared by both cases above ─────────────────────

    private function buildScheduleFromBalance(
        string $startingBalance,
        string $installmentAmount,
        string $periodicRate,
        int $numberOfInstallments,
        string $interval,
        \DateTimeInterface $firstPaymentDate,
    ): array {
        $balance = bcadd($startingBalance, '0', 2);
        $rows = [];
        $date = \DateTimeImmutable::createFromInterface($firstPaymentDate);
        $monthsPerPeriod = intdiv(12, self::PERIODS_PER_YEAR[$interval]);

        for ($period = 1; $period <= $numberOfInstallments; $period++) {
            $interest = $this->roundMoney(bcmul($balance, $periodicRate, self::SCALE));
            $principal = bcsub($installmentAmount, $interest, 2);

            // Last payment always closes the balance out exactly —
            // absorbs any rounding residue from earlier periods so
            // the schedule balances to the last cent (Building Bible
            // Rule 29.2 requires this to check out exactly).
            if ($period === $numberOfInstallments) {
                $principal = $balance;
                $interest = bcsub($installmentAmount, $principal, 2);
            }

            $balance = bcsub($balance, $principal, 2);

            $rows[] = [
                'period_number'    => $period,
                'payment_date'     => $date->format('Y-m-d'),
                'payment_amount'   => bcadd($installmentAmount, '0', 2),
                'principal_amount' => $principal,
                'interest_amount'  => $interest,
                'balance_after'    => $balance,
            ];

            $date = $date->modify("+{$monthsPerPeriod} months");
        }

        return $rows;
    }

    /**
     * Present value of a stream of equal payments — "what is this
     * whole future payment stream worth today, given the discount
     * rate." Standard ordinary-annuity formula:
     *
     *   PV = PMT × [1 − (1+r)⁻ⁿ] / r
     *
     * Guards against r = 0 (a 0%-interest lease is valid — some
     * related-party or promotional leases genuinely carry no
     * interest) since the formula above divides by r.
     */
    private function presentValue(string $installmentAmount, string $periodicRate, int $numberOfInstallments): string
    {
        if (bccomp($periodicRate, '0', self::SCALE) === 0) {
            return $this->roundMoney(bcmul($installmentAmount, (string) $numberOfInstallments, self::SCALE));
        }

        $onePlusR = bcadd('1', $periodicRate, self::SCALE);
        $onePlusRPowN = bcpow($onePlusR, (string) $numberOfInstallments, self::SCALE);
        $discountFactor = bcsub('1', bcdiv('1', $onePlusRPowN, self::SCALE), self::SCALE);
        $annuityFactor = bcdiv($discountFactor, $periodicRate, self::SCALE);

        return $this->roundMoney(bcmul($installmentAmount, $annuityFactor, self::SCALE));
    }

    /** Annual rate (as a percent, e.g. "12.5") → the rate for one single period. */
    private function periodicRate(string $annualRatePercent, string $interval): string
    {
        $periodsPerYear = self::PERIODS_PER_YEAR[$interval]
            ?? throw new \InvalidArgumentException("Unknown lease interval: {$interval}");

        return bcdiv(bcdiv($annualRatePercent, '100', self::SCALE), (string) $periodsPerYear, self::SCALE);
    }

    /**
     * bcmath truncates instead of rounding — this rounds to 2dp
     * properly (half-up), which matters here since interest gets
     * computed fresh every period and small truncation errors would
     * otherwise silently accumulate across a multi-year schedule.
     */
    private function roundMoney(string $value): string
    {
        $negative = bccomp($value, '0', self::SCALE) < 0;
        $nudge = $negative ? '-0.005' : '0.005';

        return bcadd($value, $nudge, 2);
    }

    // ══════════════════════════════════════════════════════════════
    //  PIECE 2 — Activation ("go live")
    // ══════════════════════════════════════════════════════════════

    public function __construct(
        private JournalEntryService $journalEntryService,
        private \App\Services\ChartOfAccountService $chartOfAccountService,
    ) {
    }

    /** How many months are in one installment period, for this interval. */
    public function monthsPerPeriod(string $interval): int
    {
        return intdiv(12, self::PERIODS_PER_YEAR[$interval] ?? throw new \InvalidArgumentException("Unknown lease interval: {$interval}"));
    }

    /** Total number of installments over the life of a 'new' lease, from its duration in months. */
    public function installmentCount(int $durationMonths, string $interval): int
    {
        return intdiv($durationMonths, $this->monthsPerPeriod($interval));
    }

    /**
     * Runs the calculation, saves the schedule, and — for a 'new'
     * lease only — posts the opening entry. Only applies to leases
     * that use IFRS 16 treatment (Capital, or Operational Treatment
     * B) — a Treatment A (simple expense) lease has nothing to
     * calculate and should keep using the existing, simpler
     * LeasingService::toggleContractStatus() instead.
     */
    public function activate(LeasingContract $contract, User $actor): LeasingContract
    {
        if (! $contract->isDraft()) {
            throw new LeaseAlreadyActivatedException('This lease contract is already active — its terms and schedule are locked.');
        }

        if (! $contract->usesIfrs16()) {
            throw new \DomainException('This activation path is only for Capital Lease or Operational Lease Treatment B — a Treatment A lease has no schedule to calculate.');
        }

        return DB::transaction(function () use ($contract, $actor) {
            $locked = LeasingContract::lockForUpdate()->findOrFail($contract->id);

            // Audit M5: re-check under the lock (double-click safety) and
            // refuse to build a schedule from incomplete / nonsense terms.
            if (! $locked->isDraft()) {
                throw new LeaseAlreadyActivatedException('This lease contract is already active — its terms and schedule are locked.');
            }
            $this->assertReadyForActivation($locked);

            $this->ensureAssetAccounts($locked, $actor);

            if ($locked->isNewEntry()) {
                $calc = $this->calculateForNewLease(
                    installmentAmount: (string) $locked->installment_amount,
                    annualRatePercent: (string) $locked->borrowing_rate,
                    numberOfInstallments: $this->installmentCount($locked->duration_months, $locked->installment_interval),
                    interval: $locked->installment_interval,
                    firstPaymentDate: $locked->first_payment_date,
                );
            } else {
                if (! $locked->opening_balance_amount || ! $locked->opening_remaining_installments) {
                    throw new \DomainException('An existing lease needs its opening balance and remaining number of payments entered before it can be activated.');
                }

                $calc = $this->calculateForExistingLease(
                    openingBalanceToday: (string) $locked->opening_balance_amount,
                    installmentAmount: (string) $locked->installment_amount,
                    annualRatePercent: (string) $locked->borrowing_rate,
                    remainingInstallments: $locked->opening_remaining_installments,
                    interval: $locked->installment_interval,
                    nextPaymentDate: $locked->first_payment_date,
                );
            }

            foreach ($calc['rows'] as $row) {
                LeaseAmortizationLine::create(array_merge($row, [
                    'company_id'           => $locked->company_id,
                    'leasing_contract_id'  => $locked->id,
                    'is_paid'              => false,
                ]));
            }

            // Audit M21 — cheques entered before activation get linked now.
            $this->linkChequesToSchedule($locked);

            $before = $locked->toArray();

            $locked->update([
                'status'                  => 'active',
                'activated_at'            => now(),
                'initial_lease_liability' => $calc['initial_value'],
                'initial_rou_value'       => $locked->isCapital() ? '0.00' : $calc['initial_value'],
            ]);

            // Fix (July 2026 audit note, LgIssuanceService precedent):
            // existing-lease entries never post — same principle as
            // Opening Balances. Only a genuinely 'new' lease is a real
            // accounting event today.
            if ($locked->isNewEntry()) {
                $this->postOpeningEntry($locked, $calc['initial_value'], $actor);
            }

            AuditLog::record($actor, 'leasing_contract.activated', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh(['amortizationLines']);
        });
    }

    /**
     * Audit M21 — links each real cheque to the schedule instalment it
     * pays, so the two records (kept separate on purpose, Rule 29.2) can
     * be compared instead of living side by side unconnected.
     *
     * Match rule: the nearest instalment by date, within 15 days, each
     * instalment used by at most one cheque. A cheque with no instalment
     * that close stays unlinked (lease_amortization_line_id = null).
     * Safe to run again at any time. Returns how many cheques are linked.
     */
    public function linkChequesToSchedule(LeasingContract $contract): int
    {
        $lines = LeaseAmortizationLine::withoutGlobalScopes()
            ->where('leasing_contract_id', $contract->id)->orderBy('payment_date')->get();
        $cheques = \App\Models\FinancialInstitutions\LeaseCheque::withoutGlobalScopes()
            ->where('leasing_contract_id', $contract->id)->orderBy('payment_date')->get();

        if ($lines->isEmpty() || $cheques->isEmpty()) {
            return 0;
        }

        $used   = [];
        $linked = 0;

        foreach ($cheques as $cheque) {
            $best = null;
            $bestGap = PHP_INT_MAX;

            foreach ($lines as $line) {
                if (isset($used[$line->id])) {
                    continue;
                }
                $gap = (int) abs($cheque->payment_date->startOfDay()->diffInDays($line->payment_date->startOfDay()));
                if ($gap <= 15 && $gap < $bestGap) {
                    $best = $line;
                    $bestGap = $gap;
                }
            }

            if ($best) {
                $used[$best->id] = true;
                $linked++;
            }
            $cheque->update(['lease_amortization_line_id' => $best?->id]);
        }

        return $linked;
    }

    /**
     * Audit M21 — where a linked cheque and its schedule instalment
     * disagree on the amount (lease currency). Empty = they agree.
     *
     * @return array<int, array{cheque_number:string, payment_date:string, cheque_amount:string, schedule_amount:string, difference:string}>
     */
    public function chequeScheduleDifferences(LeasingContract $contract): array
    {
        $out = [];
        $cheques = \App\Models\FinancialInstitutions\LeaseCheque::withoutGlobalScopes()
            ->where('leasing_contract_id', $contract->id)
            ->whereNotNull('lease_amortization_line_id')->orderBy('payment_date')->get();

        foreach ($cheques as $cheque) {
            $line = LeaseAmortizationLine::withoutGlobalScopes()->find($cheque->lease_amortization_line_id);
            if (! $line) {
                continue;
            }
            $diff = bcsub((string) $cheque->cheque_amount, (string) $line->payment_amount, 2);
            if (bccomp($diff, '0', 2) !== 0) {
                $out[] = [
                    'cheque_number'   => (string) $cheque->cheque_number,
                    'payment_date'    => $cheque->payment_date->toDateString(),
                    'cheque_amount'   => (string) $cheque->cheque_amount,
                    'schedule_amount' => (string) $line->payment_amount,
                    'difference'      => $diff,
                ];
            }
        }

        return $out;
    }

    /**
     * Accounts 111, 113, 114, 115 are all non-postable parents in
     * the seeded Chart of Accounts — each lease needs its own
     * postable sub-account underneath, auto-created once at
     * activation (this is what the seeder's own comments on those
     * accounts already describe as the intended design — nothing
     * had actually built it yet). Only creates what's missing —
     * safe to call even if some accounts are already assigned.
     */
    private function ensureAssetAccounts(LeasingContract $contract, User $actor): void
    {
        $label = $contract->leasingCompany->name_en . ' — ' . $contract->asset_description;

        if ($contract->isCapital()) {
            if (! $contract->fixed_asset_account_id) {
                $contract->fixed_asset_account_id = $this->createSubAccount('111', $label, $contract->company_id, $actor);
            }
            if (! $contract->accumulated_depreciation_account_id) {
                $contract->accumulated_depreciation_account_id = $this->createSubAccount('113', $label, $contract->company_id, $actor);
            }
        } else { // Operational Treatment B
            if (! $contract->rou_asset_account_id) {
                $contract->rou_asset_account_id = $this->createSubAccount('114', $label, $contract->company_id, $actor);
            }
            if (! $contract->rou_amort_account_id) {
                $contract->rou_amort_account_id = $this->createSubAccount('115', $label, $contract->company_id, $actor);
            }
        }

        $contract->save();
    }

    private function createSubAccount(string $parentCode, string $label, int $companyId, User $actor): int
    {
        $parent = $this->resolveCoaAccount($companyId, $parentCode)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$parentCode} — check the seeder.");

        $account = $this->chartOfAccountService->create($companyId, [
            'parent_id'      => $parent->id,
            'name_en'        => $label,
            'name_ar'        => null,
            'use_auto_code'  => true,
        ], $actor);

        return $account->id;
    }


    /**
     * Dr the asset (ROU for Operational B, Fixed Asset for Capital) /
     * Cr the lease liability, at the value just calculated. Posts to
     * system account 213 (Lease Liabilities — Long-Term) directly —
     * that account is postable at the system level, no per-contract
     * sub-account needed (unlike the asset side). The split into
     * "due soon" vs "due later" (Piece 4) is a calculation available
     * on request — see liabilitySplitAsOf() for why that's not a
     * second posting across two accounts today.
     */
    private function postOpeningEntry(LeasingContract $contract, string $initialValue, User $actor): void
    {
        $assetAccountId = $contract->isCapital()
            ? $contract->fixed_asset_account_id
            : $contract->rou_asset_account_id;

        $liabilityAccount = $this->resolveCoaAccount($contract->company_id, '213');

        if (! $assetAccountId || ! $liabilityAccount) {
            throw new \DomainException('This lease is missing its asset account, or the system is missing Lease Liabilities — Long-Term (213) — cannot post the opening entry.');
        }

        // C8: lease currency → functional at the historical rate.
        $initialValue = $this->fxService()->toFunctional($initialValue, $this->historicalRate($contract));

        $this->journalEntryService->post(
            companyId: $contract->company_id,
            entryDate: $contract->start_date,
            lines: [
                [
                    'chart_of_account_id' => $assetAccountId,
                    'debit'               => $initialValue,
                    'credit'              => '0',
                    'description'         => "Lease activation — {$contract->asset_description}",
                ],
                [
                    'chart_of_account_id' => $liabilityAccount->id,
                    'debit'               => '0',
                    'credit'              => $initialValue,
                    'description'         => "Lease activation — {$contract->asset_description}",
                ],
            ],
            sourceType: 'leasing_contract',
            sourceId: $contract->id,
            narration: "Lease contract {$contract->contract_reference} activated",
            actor: $actor,
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  PIECE 3 — Monthly upkeep
    // ══════════════════════════════════════════════════════════════

    /**
     * Lines due within the next $daysAhead days that haven't been
     * paid yet — for the notification job to read, never to post
     * anything itself.
     */
    public function dueSoon(int $companyId, int $daysAhead = 7): Collection
    {
        return LeaseAmortizationLine::where('company_id', $companyId)
            ->unpaid()
            ->whereBetween('payment_date', [now()->toDateString(), now()->addDays($daysAhead)->toDateString()])
            ->with('contract')
            ->get();
    }

    /**
     * Creates one notification per due-soon line, for whoever created
     * the contract — meant to run daily from the scheduler (same
     * pattern as the Time Deposit maturity check already documented
     * in the Building Bible). Never touches money — informational only.
     */
    public function notifyDuePayments(int $companyId, int $daysAhead = 7): int
    {
        $created = 0;

        foreach ($this->dueSoon($companyId, $daysAhead) as $line) {
            $exists = Notification::where('company_id', $companyId)
                ->where('source', 'lease_amortization_line:' . $line->id)
                ->where('is_read', false)
                ->exists();

            if ($exists) {
                continue;
            }

            Notification::create([
                'company_id'   => $companyId,
                'user_id'      => $line->contract->created_by,
                'type'         => 'warning',
                'severity'     => 'medium',
                'title'        => 'Lease payment due',
                'message'      => "Payment of {$line->payment_amount} for lease {$line->contract->contract_reference} is due on {$line->payment_date->format('Y-m-d')}.",
                'action_url'   => null,
                'action_label' => 'Record payment',
                'source'       => 'lease_amortization_line:' . $line->id,
                'is_read'      => false,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * The user deliberately records that a payment happened — never
     * automatic. Posts: Dr Lease Finance Charges (interest, always
     * account 556 — Building Bible Rule 29.8, hardcoded, never 551
     * or 555) + Dr Lease Liability (principal) / Cr the bank account
     * the user pays from.
     */
    public function recordPayment(LeaseAmortizationLine $line, User $actor, ?string $paidOnDate = null): LeaseAmortizationLine
    {
        if ($line->is_paid) {
            throw new \DomainException('This payment has already been recorded.');
        }

        $contract = $line->contract;

        // Audit M5: only an active lease takes payments, and the date
        // must be a real, non-future date on or after the lease start.
        if (! $contract->isActive()) {
            throw new \DomainException('Payments can only be recorded on an active lease.');
        }
        $paidOn = $this->resolveActionDate($contract, $paidOnDate, 'payment');

        $financeChargesAccount = $this->resolveCoaAccount($contract->company_id, '556');
        $liabilityAccount = $this->resolveCoaAccount($contract->company_id, '213');

        if (! $financeChargesAccount || ! $liabilityAccount || ! $contract->bank_account_id) {
            throw new \DomainException('This lease is missing an account it needs to record a payment — check that the bank account is assigned and that system accounts 556 and 213 exist.');
        }

        // C8: convert at the payment-date rate; the bank leg is the sum
        // of the converted halves so the entry always balances.
        $rate        = $this->fxService()->rateOn($contract->company_id, $contract->currency_id ? (int) $contract->currency_id : null, $paidOn->toDateString());
        $interestF   = $this->fxService()->toFunctional($line->interest_amount, $rate);
        $principalF  = $this->fxService()->toFunctional($line->principal_amount, $rate);
        $paymentF    = bcadd($interestF, $principalF, 2);

        return DB::transaction(function () use ($line, $contract, $actor, $paidOn, $financeChargesAccount, $liabilityAccount, $interestF, $principalF, $paymentF) {
            // Audit M5: lock the line so two clicks cannot pay it twice.
            if (LeaseAmortizationLine::lockForUpdate()->findOrFail($line->id)->is_paid) {
                throw new \DomainException('This payment has already been recorded.');
            }

            $entry = $this->journalEntryService->post(
                companyId: $contract->company_id,
                entryDate: $paidOn,
                lines: [
                    [
                        'chart_of_account_id' => $financeChargesAccount->id,
                        'debit'               => $interestF,
                        'credit'              => '0',
                        'description'         => "Lease payment #{$line->period_number} — {$contract->asset_description} — interest",
                    ],
                    [
                        'chart_of_account_id' => $liabilityAccount->id,
                        'debit'               => $principalF,
                        'credit'              => '0',
                        'description'         => "Lease payment #{$line->period_number} — {$contract->asset_description} — principal",
                    ],
                    [
                        'chart_of_account_id' => $contract->bankAccount->coa_account_id,
                        'debit'               => '0',
                        'credit'              => $paymentF,
                        'description'         => "Lease payment #{$line->period_number} — {$contract->asset_description}",
                    ],
                ],
                sourceType: 'lease_amortization_line',
                sourceId: $line->id,
                narration: "Lease payment #{$line->period_number} recorded for {$contract->contract_reference}",
                actor: $actor,
            );

            $line->update(['is_paid' => true, 'journal_entry_id' => $entry->id]);
            // Fix: audit_log.action is capped at 30 chars — the original
            // 40-char string was a latent bug, never yet triggered live.
            AuditLog::record($actor, 'lease_line.payment_recorded', $line, null, $line->fresh()->toArray());

            return $line->fresh();
        });
    }

    /**
     * Posts this period's asset wear-down automatically (never
     * touches cash, so this is allowed to be automatic — confirmed
     * with Mahmoud). ROU asset → 563/115xxx. Capital Lease → 561/113xxx
     * (see class header note — a stand-in until the real Fixed Assets
     * module exists). Straight-line over the lease term either way.
     */
    public function postMonthlyWearDown(LeasingContract $contract, User $actor, ?Carbon $forDate = null): LeaseWearDownEntry
    {
        if (! $contract->isActive()) {
            throw new \DomainException('Only an active lease can have its asset wear-down posted.');
        }

        // Audit M5: validated date (not future, not before lease start)
        // and at most one wear-down per installment period.
        $forDate = $this->resolveActionDate($contract, $forDate?->toDateString(), 'wear-down');
        $this->assertWearDownPeriodIsFree($contract, $forDate);

        $totalPeriods = $contract->amortizationLines()->count();
        $alreadyPosted = $contract->wearDownEntries()->where('status', 'posted')->count();

        if ($alreadyPosted >= $totalPeriods) {
            throw new \DomainException('This lease asset is already fully written down — nothing left to post.');
        }

        $baseValue = $contract->isCapital() ? $contract->initial_lease_liability : $contract->initial_rou_value;
        $periodAmount = $this->roundMoney(bcdiv($baseValue, (string) $totalPeriods, self::SCALE));
        $periodNumber = $alreadyPosted + 1;

        // Last period absorbs rounding residue, same principle as the payment schedule.
        if ($periodNumber === $totalPeriods) {
            $postedSoFar = $contract->wearDownEntries()->where('status', 'posted')->sum('amount');
            $periodAmount = bcsub($baseValue, (string) $postedSoFar, 2);
        }

        [$wearAccountId, $accumAccountId] = $contract->isCapital()
            ? [$this->resolveCoaAccount($contract->company_id, '561')?->id, $contract->accumulated_depreciation_account_id]
            : [$this->resolveCoaAccount($contract->company_id, '563')?->id, $contract->rou_amort_account_id];

        if (! $wearAccountId || ! $accumAccountId) {
            throw new \DomainException('This lease is missing its amortization/depreciation account assignment.');
        }

        // C8: the asset is non-monetary — wear-down at the historical rate.
        $periodAmountF = $this->fxService()->toFunctional($periodAmount, $this->historicalRate($contract));

        return DB::transaction(function () use ($contract, $actor, $forDate, $periodNumber, $periodAmount, $periodAmountF, $wearAccountId, $accumAccountId) {
            // Audit M5: lock the contract and re-count, so a double click
            // cannot post the same period twice.
            LeasingContract::lockForUpdate()->findOrFail($contract->id);
            if ($contract->wearDownEntries()->where('status', 'posted')->count() !== $periodNumber - 1) {
                throw new \DomainException('Another wear-down was just posted for this lease — refresh the page and try again.');
            }

            $entry = $this->journalEntryService->post(
                companyId: $contract->company_id,
                entryDate: $forDate,
                lines: [
                    ['chart_of_account_id' => $wearAccountId, 'debit' => $periodAmountF, 'credit' => '0', 'description' => "Period {$periodNumber} wear-down — {$contract->asset_description}"],
                    ['chart_of_account_id' => $accumAccountId, 'debit' => '0', 'credit' => $periodAmountF, 'description' => "Period {$periodNumber} wear-down — {$contract->asset_description}"],
                ],
                sourceType: 'leasing_contract',
                sourceId: $contract->id,
                narration: "Lease asset wear-down, period {$periodNumber} — {$contract->contract_reference}",
                actor: $actor,
            );

            $wearDown = LeaseWearDownEntry::create([
                'company_id'          => $contract->company_id,
                'leasing_contract_id' => $contract->id,
                'period_number'       => $periodNumber,
                'entry_date'          => $forDate,
                'amount'              => $periodAmount,
                'journal_entry_id'    => $entry->id,
                'status'              => 'posted',
                'posted_by'           => $actor->id,
            ]);

            AuditLog::record($actor, 'lease_wear_down_entry.posted', $wearDown, null, $wearDown->fresh()->toArray());

            return $wearDown;
        });
    }

    /**
     * The user's way of adjusting an automatic wear-down entry —
     * never a silent edit. Reverses the linked journal entry (mirror
     * image, via the existing JournalEntryService::reverse()) and
     * flags this row 'reversed'. The user then decides whether to
     * post a corrected version, terminate the lease early, or leave
     * it reversed.
     */
    public function reverseWearDown(LeaseWearDownEntry $wearDown, User $actor): LeaseWearDownEntry
    {
        if ($wearDown->status === 'reversed') {
            throw new \DomainException('This wear-down entry is already reversed.');
        }

        return DB::transaction(function () use ($wearDown, $actor) {
            $reversal = $this->journalEntryService->reverse($wearDown->journalEntry, $actor, 'Lease wear-down entry reversed by user');

            $wearDown->update([
                'status'                    => 'reversed',
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by'               => $actor->id,
                'reversed_at'               => now(),
            ]);

            AuditLog::record($actor, 'lease_wear_down_entry.reversed', $wearDown, null, $wearDown->fresh()->toArray());

            return $wearDown->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  PIECE 4 — "Due soon" vs "due later" split
    // ══════════════════════════════════════════════════════════════

    /**
     * How much of the remaining lease liability is due within the
     * next 12 months vs. beyond, as of any date — calculated live
     * from the schedule, not from separately-tracked account
     * balances.
     *
     * Note on why this is a calculation and not a posting: the
     * opening entry (postOpeningEntry) books the full liability to
     * the long-term account, and this method is what tells you, at
     * any moment, how that total should be read on a Balance Sheet.
     * Physically moving the balance between the two GL accounts each
     * period would need to reliably read live account balances,
     * which belongs with the Balance Sheet /General Ledger reporting
     * engine itself (Accounting Engine Phase 5 — not built yet, per
     * the current status report) — flagged here rather than half-
     * built ahead of that module. Once that reporting engine exists,
     * this method is exactly what it should call.
     */
    public function liabilitySplitAsOf(LeasingContract $contract, ?Carbon $asOfDate = null): array
    {
        $asOfDate ??= now();
        $twelveMonthsOut = $asOfDate->copy()->addMonths(12);

        $unpaid = $contract->amortizationLines()->unpaid()->get();

        // Fix (July 2026 audit — N2, applied): this used to cast
        // principal_amount to native PHP float inside Collection::sum()
        // and add the two totals with '+' — a real bcmath-discipline
        // regression in an otherwise clean file (Building Bible Rule 6:
        // never use float arithmetic on money). Replaced with bcadd()
        // accumulation, same precision/rounding approach used
        // throughout the rest of this service.
        $currentPortion = $unpaid
            ->filter(fn ($line) => $line->payment_date->lte($twelveMonthsOut))
            ->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->principal_amount, 2), '0.00');

        $longTermPortion = $unpaid
            ->filter(fn ($line) => $line->payment_date->gt($twelveMonthsOut))
            ->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->principal_amount, 2), '0.00');

        return [
            'as_of_date'  => $asOfDate->toDateString(),
            'current'     => $currentPortion,
            'long_term'   => $longTermPortion,
            'total'       => bcadd($currentPortion, $longTermPortion, 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  PIECE 5 — End of lease
    // ══════════════════════════════════════════════════════════════

    /**
     * The token final purchase at the end of an Operating Lease
     * Treatment B — user-triggered, never automatic (confirmed with
     * Mahmoud). Capital Lease has nothing to do here at all — its
     * asset was already recognized as owned from day one, so this
     * method refuses to run for one.
     *
     * Moves the asset from the ROU bucket to owned Fixed Assets, at
     * whatever value the user enters (typically its net book value,
     * or the token/nominal purchase price — the user's call, entered
     * directly rather than assumed).
     *
     * Fix (July 2026 audit — N1, applied): a Treatment B contract
     * legitimately has no 111xxx (Fixed Asset) account until this
     * exact moment — ensureAssetAccounts() only ever creates the
     * 114/115 (ROU) pair for Treatment B, by design, since the asset
     * isn't "owned" until final purchase happens. The old code wrongly
     * treated a missing fixed_asset_account_id as an error state and
     * threw every time. Corrected: this method now creates the 111xxx
     * sub-account itself, right here, the same way ensureAssetAccounts()
     * creates 114/115 at activation — using the same createSubAccount()
     * helper and the same "[Leasing Company] — [Asset Description]"
     * label — then saves it onto the contract before posting.
     */
    public function triggerFinalPurchase(LeasingContract $contract, User $actor, string $purchaseValue, ?string $purchaseDate = null): LeasingContract
    {
        // Audit M5: a positive amount with at most 2 decimals, and a
        // valid (non-future, not-before-start) date.
        $purchaseValue = trim($purchaseValue);
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $purchaseValue) || bccomp($purchaseValue, '0', 2) <= 0) {
            throw new \DomainException('The final purchase value must be a positive amount with at most 2 decimal places.');
        }
        $purchaseOn = $this->resolveActionDate($contract, $purchaseDate, 'final purchase');

        if ($contract->isCapital()) {
            throw new \DomainException('A Capital Lease asset is already recognized as owned — there is no final purchase step for it.');
        }

        if (! $contract->isActive()) {
            throw new \DomainException('Only an active lease can go through its final purchase.');
        }

        if (! $contract->rou_asset_account_id) {
            throw new \DomainException('This lease is missing its ROU asset account assignment — it may not have been activated correctly.');
        }

        // C8: moves the ROU asset (historical rate) into owned assets.
        $purchaseValueF = $this->fxService()->toFunctional($purchaseValue, $this->historicalRate($contract));

        return DB::transaction(function () use ($contract, $actor, $purchaseValueF, $purchaseOn) {
            // Audit M5: lock + re-check so a double click cannot buy twice.
            if (! LeasingContract::lockForUpdate()->findOrFail($contract->id)->isActive()) {
                throw new \DomainException('This lease has already been closed.');
            }

            $label = $contract->leasingCompany->name_en . ' — ' . $contract->asset_description;
            if (! $contract->fixed_asset_account_id) {
                $contract->fixed_asset_account_id = $this->createSubAccount('111', $label, $contract->company_id, $actor);
                $contract->save();
            }

            // ── Audit M21 — clear the ROU accounts completely ─────────
            // The old entry moved only the typed value out of the ROU
            // cost account, so the accumulated amortization (115xxx) and
            // part of the ROU cost (114xxx) stayed behind for ever, on a
            // lease that was closed. Now the asset moves across exactly
            // as it stands in the books — cost AND accumulated
            // amortization — so both ROU accounts end at zero and the
            // owned asset carries the same net book value.
            $balances = app(\App\Services\Accounting\AccountBalanceCalculator::class)->balancesAsOf(
                $contract->company_id,
                $purchaseOn->toDateString(),
                ChartOfAccount::withoutGlobalScopes()
                    ->whereIn('id', array_filter([$contract->rou_asset_account_id, $contract->rou_amort_account_id]))
                    ->get()->keyBy('id')
            );
            $cost  = $balances[$contract->rou_asset_account_id] ?? '0.00';
            $accum = $contract->rou_amort_account_id ? ($balances[$contract->rou_amort_account_id] ?? '0.00') : '0.00';

            if (bccomp($cost, '0', 2) > 0) {
                $netBookValue = bcsub($cost, $accum, 2);

                if (bccomp(ltrim(bcsub($purchaseValueF, $netBookValue, 2), '-'), '0.01', 2) > 0) {
                    throw new \DomainException(
                        "The leased asset's net book value on {$purchaseOn->toDateString()} is {$netBookValue} (cost {$cost} less amortization {$accum}). "
                        .'Enter that amount — the whole asset moves across, so nothing is left behind on the right-of-use accounts.'
                    );
                }

                $lines = [
                    ['chart_of_account_id' => $contract->fixed_asset_account_id, 'debit' => $cost, 'credit' => '0', 'description' => "Final purchase — {$contract->asset_description}"],
                    ['chart_of_account_id' => $contract->rou_asset_account_id, 'debit' => '0', 'credit' => $cost, 'description' => "Final purchase — {$contract->asset_description}"],
                ];

                if (bccomp($accum, '0', 2) > 0) {
                    if (! $contract->accumulated_depreciation_account_id) {
                        $contract->accumulated_depreciation_account_id = $this->createSubAccount('113', $label, $contract->company_id, $actor);
                        $contract->save();
                    }
                    $lines[] = ['chart_of_account_id' => $contract->rou_amort_account_id, 'debit' => $accum, 'credit' => '0', 'description' => "Final purchase — amortization carried over — {$contract->asset_description}"];
                    $lines[] = ['chart_of_account_id' => $contract->accumulated_depreciation_account_id, 'debit' => '0', 'credit' => $accum, 'description' => "Final purchase — amortization carried over — {$contract->asset_description}"];
                }
            } else {
                // Nothing recorded on the ROU account (e.g. an existing lease
                // entered without an opening balance) — keep the plain move.
                $lines = [
                    ['chart_of_account_id' => $contract->fixed_asset_account_id, 'debit' => $purchaseValueF, 'credit' => '0', 'description' => "Final purchase — {$contract->asset_description}"],
                    ['chart_of_account_id' => $contract->rou_asset_account_id, 'debit' => '0', 'credit' => $purchaseValueF, 'description' => "Final purchase — {$contract->asset_description}"],
                ];
            }

            $this->journalEntryService->post(
                companyId: $contract->company_id,
                entryDate: $purchaseOn,
                lines: $lines,
                sourceType: 'leasing_contract',
                sourceId: $contract->id,
                narration: "Lease {$contract->contract_reference} — final purchase, asset now owned outright",
                actor: $actor,
            );

            $before = $contract->toArray();
            $contract->update(['status' => 'closed']);
            // Fix: audit_log.action is capped at 30 chars — the original
            // 31-char string was a latent bug, never yet triggered live.
            AuditLog::record($actor, 'leasing_contract.final_buy', $contract, $before, $contract->fresh()->toArray());

            return $contract->fresh();
        });
    }

    /** Same pattern as ChartOfAccount::findByCode() elsewhere — system accounts have company_id = NULL. */
    private function resolveCoaAccount(int $companyId, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code);
    }

    // ── Currency (Audit C8, Sept 2026) ─────────────────────────────

    private function fxService(): \App\Services\Accounting\FxService
    {
        return app(\App\Services\Accounting\FxService::class);
    }

    /** The lease's historical rate: typed on the contract, else the rate on its start date. */
    private function historicalRate(LeasingContract $contract): string
    {
        $start = $contract->start_date ? Carbon::parse($contract->start_date)->toDateString() : now()->toDateString();

        return $this->fxService()->resolveRate(
            $contract->company_id,
            $contract->currency_id ? (int) $contract->currency_id : null,
            $start,
            $contract->exchange_rate
        );
    }

    // ── Input guards (Audit M5, Sept 2026) ─────────────────────────

    /**
     * Turns an optional Y-m-d string into the date a lease action is
     * posted on. Blank → today. Refuses anything that is not a real
     * calendar date, a future date, or a date before the lease's own
     * start date (a lease cannot be paid, worn down or bought out
     * before it exists).
     */
    private function resolveActionDate(LeasingContract $contract, ?string $date, string $label): Carbon
    {
        // "Today" in Cairo, not server UTC — otherwise between 00:00 and
        // 03:00 Cairo time a correct date is refused as "future" (see
        // audit M40). Dates are compared as plain calendar days.
        $today = Carbon::createFromFormat('!Y-m-d', now('Africa/Cairo')->toDateString());

        if ($date === null || trim($date) === '') {
            $on = $today;
        } else {
            $on = Carbon::createFromFormat('!Y-m-d', trim($date));
            if (! $on || $on->format('Y-m-d') !== trim($date)) {
                throw new \DomainException("The {$label} date is not a valid date (expected YYYY-MM-DD).");
            }
        }

        if ($on->gt($today)) {
            throw new \DomainException("The {$label} date cannot be in the future.");
        }

        if ($contract->start_date && $on->lt(Carbon::parse($contract->start_date)->startOfDay())) {
            throw new \DomainException("The {$label} date cannot be before the lease start date ({$contract->start_date->format('Y-m-d')}).");
        }

        return $on;
    }

    /**
     * A lease is only activated from complete, sensible terms. Without
     * this, a zero / negative installment or a missing first payment
     * date produced a meaningless schedule and opening entry.
     */
    private function assertReadyForActivation(LeasingContract $contract): void
    {
        $problems = [];

        if (! is_numeric($contract->installment_amount) || bccomp((string) $contract->installment_amount, '0', 2) <= 0) {
            $problems[] = 'installment amount must be greater than zero';
        }
        if (! is_numeric($contract->borrowing_rate) || bccomp((string) $contract->borrowing_rate, '0', 4) < 0 || bccomp((string) $contract->borrowing_rate, '100', 4) > 0) {
            $problems[] = 'borrowing rate must be between 0% and 100%';
        }
        if (! $contract->first_payment_date) {
            $problems[] = 'first payment date is missing';
        }
        if (! array_key_exists((string) $contract->installment_interval, self::PERIODS_PER_YEAR)) {
            $problems[] = 'installment interval is not set';
        } elseif ($contract->isNewEntry() && $this->installmentCount((int) $contract->duration_months, $contract->installment_interval) < 1) {
            $problems[] = 'duration is shorter than one installment period';
        }

        if ($problems) {
            throw new \DomainException('This lease cannot be activated yet: ' . implode('; ', $problems) . '.');
        }
    }

    /**
     * One wear-down per installment period: the new date must fall at
     * least one full period (1, 3, 6 or 12 months, by the lease's
     * interval) after the month of the last posted wear-down, and
     * never before it. Stops a double click — or clicking "post" twice
     * in one month — from writing the asset down twice.
     */
    private function assertWearDownPeriodIsFree(LeasingContract $contract, Carbon $forDate): void
    {
        $last = $contract->wearDownEntries()
            ->where('status', 'posted')
            ->orderByDesc('entry_date')
            ->first();

        if (! $last) {
            return;
        }

        $lastDate  = Carbon::parse($last->entry_date);
        $gapMonths = ($forDate->year * 12 + $forDate->month) - ($lastDate->year * 12 + $lastDate->month);
        $period    = $this->monthsPerPeriod($contract->installment_interval);

        if ($gapMonths < $period) {
            $next = $lastDate->copy()->startOfMonth()->addMonths($period)->format('Y-m-d');
            throw new \DomainException("A wear-down is already posted for this period (last one dated {$lastDate->format('Y-m-d')}). The next one can be posted from {$next}.");
        }
    }
}
