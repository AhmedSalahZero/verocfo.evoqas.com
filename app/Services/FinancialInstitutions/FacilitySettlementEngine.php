<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilitySettlementEngine
//
//  Building Structure Bible Rule 34 — the ONE shared settlement
//  engine for every OD-family facility (Clean OD, Commercial Paper
//  OD, Contract-Assignment OD, Fully Secured OD —
//  CreditFacility::isOdFamily()). Never copy-pasted per facility
//  type (Rule 34.1) — every *Service class for those four facility
//  types is expected to delegate here, not reimplement any of this.
//
//  WHAT THIS CLASS DOES NOT DO: post a journal entry for a
//  withdrawal or a settlement. By the time recordWithdrawal() or
//  recordSettlement() is called, the real cash movement has already
//  been journaled by whoever triggered it — SupplierPaymentService
//  (a facility as payment source), CustomerPaymentService (a
//  facility as payment destination), or InternalMoneyTransferService
//  (a facility as either leg). This engine only maintains the
//  SUB-LEDGER: which withdrawal is outstanding, which charge is
//  unpaid, and in what order an incoming credit clears them. Posting
//  a second journal entry for money already journaled would double
//  it in the GL. The one exception is postLiabilityCharge() —
//  interest/HDB/admin fee — a brand-new liability event nothing else
//  creates, so that method genuinely does post its own entry.
//
//  Rule 34.2 — priority order for recordSettlement(), enforced
//  exactly, never spilling partially into the next bucket before the
//  current one is fully cleared:
//    1. Interest charges         (oldest charge_date first)
//    2. Highest Debit Balance fees (oldest charge_date first)
//    3. Admin fees                (oldest charge_date first)
//    4. Withdrawals               (oldest due_date first)
//
//  Rule 34.3 — every withdrawal carries its own due_date (from the
//  facility's settled_max_days AT THE MOMENT of the drawdown, not
//  looked up fresh later) and a net_balance (remaining_amount here)
//  that depletes to exactly 0.00 — bcmath throughout, never float.
//
//  Rule 34.5 — reversal restores the withdrawal's/charge's
//  remaining_amount to what it was before, never a silent delete.
//
//  Rule 34.7 — every row this engine writes carries its own
//  bank_account_id.
//
//  NOT YET BUILT (deliberately out of scope for this pass — see
//  Bible Section 34 header note on the daily job, and Rule 34.4):
//    - Rule 34.4 resettlement of the REAL sub-ledger/GL (withdrawals,
//      settlements, charges) when an earlier transaction changes —
//      deliberately NOT built, and deliberately NEVER will be
//      silent/automatic. Mahmoud confirmed (2026-09): in VeroCFO, no
//      transaction is ever corrected outside the clean reopen/cancel
//      path on the transaction that created it, because unlike
//      CashVero (pure treasury, no GL) a silent cascade here would
//      leave the GL and this sub-ledger disagreeing with no audit
//      trail. updateDailyBalance() below now DOES cascade forward —
//      see its docblock — but that is a derived REPORTING table with
//      no journal entry behind it, not the real sub-ledger, so
//      recomputing it deterministically carries none of that risk.
//
//  @layer    Service
//  @depends  CreditFacility, FacilityWithdrawal, FacilityLiabilityCharge,
//            FacilitySettlement, FacilitySettlementAllocation,
//            FacilityDailyBalance, ChartOfAccount, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Models\FinancialInstitutions\FacilitySettlementAllocation;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Support\Facades\DB;

class FacilitySettlementEngine
{
    public function __construct(
        private JournalEntryService $journalEntryService,
    ) {}

    // ── Drawdown ─────────────────────────────────────────────────

    /**
     * Records money drawn FROM an OD-family facility. The caller
     * (SupplierPaymentService / InternalMoneyTransferService) has
     * already posted the real journal entry — this only creates the
     * sub-ledger row and grows the facility's running balance.
     *
     * $data keys: bank_account_id, withdrawal_date, amount,
     *   source_type (FacilityWithdrawal::SOURCE_*), source_id,
     *   journal_entry_id, description (optional)
     */
    public function recordWithdrawal(CreditFacility $facility, array $data, User $actor): FacilityWithdrawal
    {
        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException('Withdrawal amount must be greater than zero.');
        }

        return DB::transaction(function () use ($facility, $data, $actor, $amount) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($facility->id);

            $newOutstanding = bcadd((string) $facility->outstanding_balance, $amount, 2);
            if (bccomp($newOutstanding, (string) $facility->facility_limit, 2) > 0) {
                throw new \DomainException(
                    "This drawdown would push the facility's outstanding balance ({$newOutstanding}) ".
                    "beyond its limit ({$facility->facility_limit})."
                );
            }

            $withdrawalDate = $data['withdrawal_date'];
            $dueDate = date('Y-m-d', strtotime($withdrawalDate.' +'.((int) $facility->settled_max_days).' days'));

            $withdrawal = FacilityWithdrawal::create([
                'company_id'         => $facility->company_id,
                'credit_facility_id' => $facility->id,
                'bank_account_id'    => $data['bank_account_id'],
                'withdrawal_date'    => $withdrawalDate,
                'due_date'           => $dueDate,
                'amount'             => $amount,
                'settled_amount'     => '0.00',
                'remaining_amount'   => $amount,
                'status'             => 'open',
                'is_opening_balance' => false,
                'source_type'        => $data['source_type'],
                'source_id'          => $data['source_id'],
                'description'        => $data['description'] ?? null,
                'journal_entry_id'   => $data['journal_entry_id'] ?? null,
            ]);

            $facility->update(['outstanding_balance' => $newOutstanding]);

            $this->updateDailyBalance($facility, $withdrawalDate, withdrawalsDelta: $amount);

            AuditLog::record($actor, 'facility_withdrawal.recorded', $withdrawal, null, $withdrawal->fresh()->toArray());

            return $withdrawal;
        });
    }

    /**
     * Undoes a withdrawal that has NEVER had any settlement applied
     * to it (isLocked() === false). Used when the source transaction
     * that created it (a Supplier Payment / Internal Transfer) is
     * itself cancelled before anything settled against it. If any
     * settlement history exists, the caller must reverse those
     * settlements first — never silently strip settled money off a
     * withdrawal that's already been (partly) paid down.
     */
    public function reverseWithdrawal(FacilityWithdrawal $withdrawal, User $actor): void
    {
        if ($withdrawal->isLocked()) {
            throw new \DomainException(
                'This withdrawal has settlement history against it — reverse the settlement(s) first.'
            );
        }

        DB::transaction(function () use ($withdrawal, $actor) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($withdrawal->credit_facility_id);

            $facility->update([
                'outstanding_balance' => bcsub((string) $facility->outstanding_balance, (string) $withdrawal->amount, 2),
            ]);

            $this->updateDailyBalance($facility, $withdrawal->withdrawal_date->toDateString(), withdrawalsDelta: bcmul((string) $withdrawal->amount, '-1', 2));

            AuditLog::record($actor, 'facility_withdrawal.reversed', $withdrawal, $withdrawal->toArray(), null);

            $withdrawal->delete();
        });
    }

    // ── Charge posting (interest / HDB / admin fee) ─────────────────

    /**
     * Posts a pending liability charge to the GL — the one method in
     * this engine that DOES post a new journal entry (Dr Interest
     * Expense/Bank Charges, Cr the facility's own COA account),
     * since nothing else in the system creates this liability event.
     * Once posted, it enters the settlement priority queue (Rule
     * 34.2) via scopeSettleable().
     *
     * $data keys: charge_type (interest|highest_debit_balance|admin_fee),
     *   period_label, charge_date, theoretical_amount, actual_amount,
     *   bank_account_id (optional — defaults to the facility's own)
     */
    public function postLiabilityCharge(CreditFacility $facility, array $data, User $actor): FacilityLiabilityCharge
    {
        $actualAmount = (string) $data['actual_amount'];

        if (bccomp($actualAmount, '0', 2) <= 0) {
            throw new \DomainException('Charge amount must be greater than zero.');
        }

        return DB::transaction(function () use ($facility, $data, $actor, $actualAmount) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($facility->id);

            $expenseAccountCode = match ($data['charge_type']) {
                'interest'              => '555', // Overdraft Interest
                'highest_debit_balance' => '552', // Bank Charges & Commissions
                'admin_fee'             => '552', // Bank Charges & Commissions
                default                 => throw new \DomainException('Unknown charge type: '.$data['charge_type']),
            };

            $expenseAccount = ChartOfAccount::findByCode($facility->company_id, $expenseAccountCode)
                ?? throw new \DomainException("Chart of Accounts is missing system account {$expenseAccountCode}.");

            if (! $facility->coa_account_id) {
                throw new \DomainException('This facility has no Chart of Accounts account linked.');
            }

            // Currency bug fix (2026-09) — actual_amount is entered from
            // the bank statement, in the FACILITY's own currency (matches
            // theoretical_amount, remaining_amount, and outstanding_balance
            // below — all native). But this app's journal entries are
            // always posted in FUNCTIONAL currency (see
            // JournalEntryService::post() — no per-line FX conversion
            // happens there, callers must convert first). Without this,
            // a foreign-currency facility's charge would post to the GL
            // understated/overstated by the exchange rate factor, exactly
            // the same class of bug just fixed in SupplierPaymentService/
            // CustomerPaymentService/InternalMoneyTransferService.
            // Audit M23 (Oct 2026): FxService returns 1 for the company's own
            // (functional) currency. The raw ExchangeRate lookup found no
            // row for it and refused to post the bank's interest charge on
            // an Egyptian-pound facility. A foreign currency without a rate
            // still stops with a clear message.
            $exchangeRate = app(\App\Services\Accounting\FxService::class)
                ->rateOn((int) $facility->company_id, $facility->currency_id ? (int) $facility->currency_id : null, $data['charge_date']);
            $actualAmountFunctional = bcmul($actualAmount, $exchangeRate, 2);

            $theoretical = (string) ($data['theoretical_amount'] ?? $actualAmount);
            $difference  = bcsub($actualAmount, $theoretical, 2);
            $label = ucfirst(str_replace('_', ' ', $data['charge_type']));

            // Create the row first (no journal_entry_id yet) so the
            // journal entry below can reference its real id as
            // sourceId — same create-then-post order used throughout
            // this codebase (see CustomerPaymentService::record()).
            $charge = FacilityLiabilityCharge::create([
                'company_id'          => $facility->company_id,
                'credit_facility_id'  => $facility->id,
                'bank_account_id'     => $data['bank_account_id'] ?? $facility->bank_account_id,
                'charge_type'         => $data['charge_type'],
                'period_label'        => $data['period_label'],
                'charge_date'         => $data['charge_date'],
                'theoretical_amount'  => $theoretical,
                'actual_amount'       => $actualAmount,
                'settled_amount'      => '0.00',
                'remaining_amount'    => $actualAmount,
                'difference_amount'   => $difference,
                'user_overridden'     => $data['user_overridden'] ?? false,
                'override_reason'     => $data['override_reason'] ?? null,
                'status'              => 'posted',
                'posted_by'           => $actor->id,
                'posted_at'           => now(),
            ]);

            $entry = $this->journalEntryService->post(
                companyId: $facility->company_id,
                entryDate: $data['charge_date'],
                lines: [
                    ['chart_of_account_id' => $expenseAccount->id, 'debit' => $actualAmountFunctional, 'credit' => '0', 'description' => $label.' — '.$facility->facility_reference],
                    ['chart_of_account_id' => $facility->coa_account_id, 'debit' => '0', 'credit' => $actualAmountFunctional, 'description' => $label.' — '.$facility->facility_reference],
                ],
                sourceType: 'facility_liability_charge',
                sourceId: $charge->id,
                narration: "{$label} posted — {$data['period_label']}",
                actor: $actor,
            );

            $charge->update(['journal_entry_id' => $entry->id]);

            // outstanding_balance stays in the facility's own currency —
            // uses actualAmount (native), never the functional figure.
            $facility->update([
                'outstanding_balance' => bcadd((string) $facility->outstanding_balance, $actualAmount, 2),
            ]);

            $this->updateDailyBalance($facility, $data['charge_date'], chargesDelta: $actualAmount);

            AuditLog::record($actor, 'facility_liability_charge.posted', $charge, null, $charge->fresh()->toArray());

            return $charge;
        });
    }

    // ── Settlement ───────────────────────────────────────────────

    /**
     * Applies an incoming credit against a facility in strict Rule
     * 34.2 priority order. The caller has already posted the real
     * journal entry — this only decides which charges/withdrawals it
     * pays off and records that split.
     *
     * $data keys: bank_account_id, settlement_date, amount,
     *   source_type (FacilitySettlement::SOURCE_*), source_id,
     *   journal_entry_id
     */
    public function recordSettlement(CreditFacility $facility, array $data, User $actor): FacilitySettlement
    {
        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException('Settlement amount must be greater than zero.');
        }

        return DB::transaction(function () use ($facility, $data, $actor, $amount) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($facility->id);

            if (bccomp($amount, (string) $facility->outstanding_balance, 2) > 0) {
                throw new \DomainException(
                    "Settlement amount ({$amount}) exceeds the facility's total outstanding balance ".
                    "({$facility->outstanding_balance})."
                );
            }

            $settlement = FacilitySettlement::create([
                'company_id'         => $facility->company_id,
                'credit_facility_id' => $facility->id,
                'bank_account_id'    => $data['bank_account_id'],
                'settlement_date'    => $data['settlement_date'],
                'amount'             => $amount,
                'source_type'        => $data['source_type'],
                'source_id'          => $data['source_id'],
                'journal_entry_id'   => $data['journal_entry_id'],
                'status'             => FacilitySettlement::STATUS_POSTED,
                'created_by'         => $actor->id,
            ]);

            $remaining = $amount;
            $remaining = $this->applyToCharges($facility, $settlement, 'interest', $remaining);
            $remaining = $this->applyToCharges($facility, $settlement, 'highest_debit_balance', $remaining);
            $remaining = $this->applyToCharges($facility, $settlement, 'admin_fee', $remaining);
            $remaining = $this->applyToWithdrawals($facility, $settlement, $remaining);

            // Guarded by the outstanding_balance check above — this
            // would only fire if the sub-ledger has drifted out of
            // sync with outstanding_balance, which should never
            // happen if every movement goes through this engine.
            if (bccomp($remaining, '0', 2) > 0) {
                throw new \DomainException(
                    "Internal inconsistency: {$remaining} of this settlement could not be allocated ".
                    'to any open charge or withdrawal. Facility sub-ledger may be out of sync — please review before retrying.'
                );
            }

            $facility->update([
                'outstanding_balance' => bcsub((string) $facility->outstanding_balance, $amount, 2),
            ]);

            $this->updateDailyBalance($facility, $data['settlement_date'], settlementsDelta: $amount);

            AuditLog::record($actor, 'facility_settlement.recorded', $settlement, null, $settlement->fresh('allocations')->toArray());

            return $settlement->fresh('allocations');
        });
    }

    /**
     * Reverses a posted settlement — restores every charge's/
     * withdrawal's remaining_amount to what it was before this
     * settlement applied, and grows the facility's outstanding
     * balance back. Never deletes the settlement row (Rule 34.5) —
     * flags it 'reversed' instead. Does NOT reverse the underlying
     * Customer Payment's / Internal Transfer's own journal entry —
     * that stays the caller's responsibility.
     */
    public function reverseSettlement(FacilitySettlement $settlement, User $actor): void
    {
        if ($settlement->isReversed()) {
            throw new \DomainException('This settlement has already been reversed.');
        }

        DB::transaction(function () use ($settlement, $actor) {
            $before = $settlement->toArray();

            $facility = CreditFacility::lockForUpdate()->findOrFail($settlement->credit_facility_id);

            foreach ($settlement->allocations()->get() as $allocation) {
                if ($allocation->allocatable_type === FacilitySettlementAllocation::TYPE_CHARGE) {
                    $charge = FacilityLiabilityCharge::lockForUpdate()->find($allocation->allocatable_id);
                    if ($charge) {
                        $charge->update([
                            'settled_amount'   => bcsub((string) $charge->settled_amount, (string) $allocation->amount, 2),
                            'remaining_amount' => bcadd((string) $charge->remaining_amount, (string) $allocation->amount, 2),
                        ]);
                    }
                } else {
                    $withdrawal = FacilityWithdrawal::lockForUpdate()->find($allocation->allocatable_id);
                    if ($withdrawal) {
                        $newSettled = bcsub((string) $withdrawal->settled_amount, (string) $allocation->amount, 2);
                        $newRemaining = bcadd((string) $withdrawal->remaining_amount, (string) $allocation->amount, 2);
                        $withdrawal->update([
                            'settled_amount'   => $newSettled,
                            'remaining_amount' => $newRemaining,
                            'status'           => bccomp($newSettled, '0', 2) <= 0 ? 'open' : 'partial',
                        ]);
                    }
                }
            }

            $facility->update([
                'outstanding_balance' => bcadd((string) $facility->outstanding_balance, (string) $settlement->amount, 2),
            ]);

            $this->updateDailyBalance($facility, $settlement->settlement_date->toDateString(), settlementsDelta: bcmul((string) $settlement->amount, '-1', 2));

            $settlement->update([
                'status'      => FacilitySettlement::STATUS_REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ]);

            AuditLog::record($actor, 'facility_settlement.reversed', $settlement, $before, $settlement->fresh()->toArray());
        });
    }

    // ── Private — priority allocation ───────────────────────────────

    private function applyToCharges(CreditFacility $facility, FacilitySettlement $settlement, string $chargeType, string $remaining): string
    {
        if (bccomp($remaining, '0', 2) <= 0) {
            return $remaining;
        }

        $charges = FacilityLiabilityCharge::where('credit_facility_id', $facility->id)
            ->where('charge_type', $chargeType)
            ->settleable()
            ->lockForUpdate()
            ->get();

        foreach ($charges as $charge) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $applied = bccomp($remaining, (string) $charge->remaining_amount, 2) >= 0
                ? (string) $charge->remaining_amount
                : $remaining;

            FacilitySettlementAllocation::create([
                'company_id'              => $facility->company_id,
                'facility_settlement_id'  => $settlement->id,
                'allocatable_type'        => FacilitySettlementAllocation::TYPE_CHARGE,
                'allocatable_id'          => $charge->id,
                'amount'                  => $applied,
            ]);

            $charge->update([
                'settled_amount'   => bcadd((string) $charge->settled_amount, $applied, 2),
                'remaining_amount' => bcsub((string) $charge->remaining_amount, $applied, 2),
            ]);

            $remaining = bcsub($remaining, $applied, 2);
        }

        return $remaining;
    }

    private function applyToWithdrawals(CreditFacility $facility, FacilitySettlement $settlement, string $remaining): string
    {
        if (bccomp($remaining, '0', 2) <= 0) {
            return $remaining;
        }

        $withdrawals = FacilityWithdrawal::where('credit_facility_id', $facility->id)
            ->settleable()
            ->lockForUpdate()
            ->get();

        foreach ($withdrawals as $withdrawal) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $applied = bccomp($remaining, (string) $withdrawal->remaining_amount, 2) >= 0
                ? (string) $withdrawal->remaining_amount
                : $remaining;

            FacilitySettlementAllocation::create([
                'company_id'              => $facility->company_id,
                'facility_settlement_id'  => $settlement->id,
                'allocatable_type'        => FacilitySettlementAllocation::TYPE_WITHDRAWAL,
                'allocatable_id'          => $withdrawal->id,
                'amount'                  => $applied,
            ]);

            $newSettled   = bcadd((string) $withdrawal->settled_amount, $applied, 2);
            $newRemaining = bcsub((string) $withdrawal->remaining_amount, $applied, 2);

            $withdrawal->update([
                'settled_amount'   => $newSettled,
                'remaining_amount' => $newRemaining,
                'status'           => bccomp($newRemaining, '0', 2) <= 0 ? 'settled' : 'partial',
            ]);

            $remaining = bcsub($remaining, $applied, 2);
        }

        return $remaining;
    }

    // ── Private — running daily balance ───────────────────────────

    /**
     * Records this transaction's own movement on its date, THEN
     * cascades forward: recomputes opening/closing/interest for every
     * stored day from this date through the most recent stored day,
     * each one's opening now correctly chained off the day before it.
     * This is what makes a backdated correction (reopening an old
     * payment, editing the amount, re-posting) show correctly on
     * every later day's snapshot too — not just today's live
     * outstanding_balance, which was always correct on its own (see
     * recordWithdrawal()/recordSettlement() — that's a separate
     * running total, adjusted directly, untouched by any of this).
     *
     * Safe to recompute freely: this table carries no journal entry
     * of its own (see class header) — it's a deterministic function
     * of each day's own stored withdrawals_today/charges_today/
     * settlements_today plus the day before's closing balance, so
     * recomputing it is not a "resettlement" of anything real.
     *
     * Does NOT create rows for days with no transaction and no
     * existing row (e.g. yesterday, if nothing happened) — that gap
     * is what FacilityDailyAccrualJob (accrueDailyForAllFacilities())
     * fills in daily, so every day in between has a real row for this
     * cascade to walk through.
     */
    private function updateDailyBalance(
        CreditFacility $facility,
        string $date,
        string $withdrawalsDelta = '0',
        string $chargesDelta = '0',
        string $settlementsDelta = '0'
    ): void {
        $existing = FacilityDailyBalance::where('credit_facility_id', $facility->id)
            ->where('balance_date', $date)
            ->first();

        $withdrawalsToday = bcadd($existing ? (string) $existing->withdrawals_today : '0', $withdrawalsDelta, 2);
        $chargesToday     = bcadd($existing ? (string) $existing->charges_today : '0', $chargesDelta, 2);
        $settlementsToday = bcadd($existing ? (string) $existing->settlements_today : '0', $settlementsDelta, 2);

        FacilityDailyBalance::updateOrCreate(
            ['credit_facility_id' => $facility->id, 'balance_date' => $date],
            [
                'company_id'        => $facility->company_id,
                // opening_balance, closing_balance, and
                // daily_interest_amount all have no DB default and
                // this table's very first row for a facility+date
                // combination has none to inherit either — without
                // setting SOMETHING here, a brand-new row fails its
                // NOT NULL constraints before ever reaching
                // recomputeForwardFrom() below, which immediately
                // overwrites all three placeholders with the real,
                // correctly-chained values anyway. (First pass at this
                // fix only caught opening_balance — closing_balance
                // and daily_interest_amount are the exact same
                // problem, just the next columns over; fixing all
                // three together this time.)
                'opening_balance'       => $existing->opening_balance ?? '0',
                'closing_balance'       => $existing->closing_balance ?? '0',
                'daily_interest_amount' => $existing->daily_interest_amount ?? '0',
                'withdrawals_today'     => $withdrawalsToday,
                'charges_today'         => $chargesToday,
                'settlements_today'     => $settlementsToday,
            ]
        );

        $this->recomputeForwardFrom($facility, $date);
    }

    /**
     * The cascade itself — every stored day from $fromDate onward,
     * oldest first, each one's opening chained off the previous day's
     * freshly-recomputed closing. See updateDailyBalance() docblock.
     */
    private function recomputeForwardFrom(CreditFacility $facility, string $fromDate): void
    {
        $opening = FacilityDailyBalance::where('credit_facility_id', $facility->id)
            ->where('balance_date', '<', $fromDate)
            ->orderByDesc('balance_date')
            ->value('closing_balance');
        $opening = $opening !== null ? (string) $opening : '0.00';

        $dailyRate = bcdiv((string) $facility->interest_rate, '100', 10);

        FacilityDailyBalance::where('credit_facility_id', $facility->id)
            ->where('balance_date', '>=', $fromDate)
            ->orderBy('balance_date')
            ->lockForUpdate()
            ->get()
            ->each(function (FacilityDailyBalance $row) use (&$opening, $dailyRate) {
                $closing = bcsub(bcadd(bcadd($opening, (string) $row->withdrawals_today, 2), (string) $row->charges_today, 2), (string) $row->settlements_today, 2);
                $dailyInterest = bcdiv(bcmul($closing, $dailyRate, 10), '360', 6);

                $row->update([
                    'opening_balance'       => $opening,
                    'closing_balance'       => $closing,
                    'daily_interest_amount' => $dailyInterest,
                ]);

                $opening = $closing;
            });
    }

    /**
     * The daily job (FacilityDailyAccrualJob) — ensures every active
     * OD-family facility has a snapshot row for $date (defaults to
     * today), carrying forward the previous day's closing balance
     * with zero movements if nothing happened. Without this, a quiet
     * day has no row at all, so the monthly theoretical-interest
     * comparison shown when posting a charge undercounts. Scoped
     * across every company itself (withoutGlobalScopes() + explicit
     * company_id read off each facility), matching
     * LgIssuanceService::postDueCommissionAccruals()'s own convention
     * — this stays a thin per-facility loop, not a second competing
     * iteration style.
     */
    public function accrueDailyForAllFacilities(?string $date = null): void
    {
        $date = $date ?? now()->format('Y-m-d');

        CreditFacility::withoutGlobalScopes()
            ->odFamily()
            ->where('status', 'active')
            // Audit H9: never for an inactive (suspended) company.
            ->whereIn('company_id', \App\Models\Company::withoutGlobalScopes()->where('is_active', true)->select('id'))
            ->chunkById(100, function ($facilities) use ($date) {
                foreach ($facilities as $facility) {
                    $this->accrueOneFacilitySafely($facility, $date);
                }
            });
    }

    /**
     * Audit H9 (Sept 2026) — one company only, every query filtered by
     * company_id explicitly. FacilityDailyAccrualJob calls this once per
     * active company inside TenantContext::runAs(), so one company's
     * failing facility no longer stops the run for everyone else.
     *
     * Audit M25 (Oct 2026) — one FACILITY failing no longer stops the
     * other facilities of the same company either.
     */
    public function accrueDailyForCompany(int $companyId, ?string $date = null): void
    {
        $date = $date ?? now()->format('Y-m-d');

        CreditFacility::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->odFamily()
            ->where('status', 'active')
            ->chunkById(100, function ($facilities) use ($date) {
                foreach ($facilities as $facility) {
                    $this->accrueOneFacilitySafely($facility, $date);
                }
            });
    }

    /**
     * Audit M25 — a failing facility is logged and skipped; the loop
     * carries on with the next one.
     */
    private function accrueOneFacilitySafely(CreditFacility $facility, string $date): void
    {
        try {
            $this->catchUpDailyBalances($facility, $date);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Daily accrual failed for facility #{$facility->id} (company #{$facility->company_id}): ".$e->getMessage());
        }
    }

    /**
     * Audit M25 — catch-up for missed days. The daily job used to
     * write only TODAY's row, so one missed run (server down, job
     * failed) left a permanent hole in the history and the interest
     * for that day was lost from the monthly theoretical figure.
     *
     * Now every calendar day from the facility's first stored row up
     * to $date that has no row gets one (zero movements, carrying the
     * previous closing balance forward). Days are filled oldest first
     * so each one chains off the day before. A facility with no rows
     * at all gets just $date — its history starts there.
     *
     * Safe to run twice: days that already have a row are left alone.
     */
    public function catchUpDailyBalances(CreditFacility $facility, string $date): int
    {
        $existing = FacilityDailyBalance::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)
            ->pluck('balance_date')
            ->map(fn ($d) => substr((string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d), 0, 10))
            ->flip();

        $first = $existing->isEmpty() ? $date : (string) $existing->keys()->sort()->first();
        $cursor = \Carbon\Carbon::parse(min($first, $date));
        $end    = \Carbon\Carbon::parse($date);
        $filled = 0;

        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            if (! $existing->has($day)) {
                DB::transaction(fn () => $this->updateDailyBalance($facility, $day));
                $filled++;
            }
            $cursor->addDay();
        }

        return $filled;
    }
}
