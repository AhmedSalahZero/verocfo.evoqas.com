<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BorrowingRateService
//
//  Handles CBE Corridor Rate updates and propagation.
//
//  When a new corridor rate is saved:
//    1. Records the new CbeCorridorRate entry with previous rate + direction
//    2. Finds all active facilities with follows_corridor_rate = true
//    3. Updates each facility's borrowing_rate to the new corridor rate
//    4. Recalculates interest_rate = new borrowing_rate + bank_margin_rate
//    5. Updates facility_daily_balances from effective_date onwards:
//       - Days before effective_date → old daily interest unchanged
//       - Days from effective_date → recalculate with new rate
//       (Option A — mid-month daily split, as agreed)
//    6. Writes audit log for each affected facility
//
//  Interest formula (360-day Egyptian standard):
//    daily_interest = closing_balance × (new_annual_rate / 100) / 360
//    All calculations use bcmath.
//
//  Audit H6 (Sept 2026): corridor rates are COMPANY-OWNED. The
//  previous rate is this company's own latest rate, the new record
//  carries company_id, and only this company's facilities update.
//
//  AUDIT M24 FIX (Oct 2026) — a BACK-DATED rate used to re-price the
//  past silently. Now:
//    • Only the stretch the new rate really governs is re-priced:
//      from its effective date up to (not including) the next rate's
//      effective date. Before, every day after it was overwritten,
//      even days a later rate should have covered.
//    • A rate that is not the latest in force no longer overwrites
//      the facility's CURRENT interest rate with an old one.
//    • For every facility whose past days change, a true-up record
//      (FacilityRateTrueup) is written: theoretical interest before,
//      after, the difference, and how much interest the user had
//      already posted from bank statements for those months. The
//      ledger is deliberately NOT changed — it follows the bank's
//      real statements — so the true-up record is what tells the
//      user which already-posted months now differ from theory.
//
//  @layer    Service
//  @depends  CbeCorridorRate, CreditFacility, FacilityDailyBalance,
//            FacilityRateTrueup, FacilityLiabilityCharge, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CbeCorridorRate;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\FacilityRateTrueup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BorrowingRateService
{
    /** True-up records written by the most recent updateCorridorRate() call. */
    private array $lastTrueups = [];

    /** @return FacilityRateTrueup[] */
    public function lastTrueups(): array
    {
        return $this->lastTrueups;
    }

    /**
     * Message for the screen after a rate update. When a back-dated
     * rate changed past interest, says so plainly — and says whether
     * interest for those months was already posted from bank statements.
     */
    public function successMessage(): string
    {
        $message = 'Borrowing rate updated successfully. All linked facilities have been updated.';
        $count   = count($this->lastTrueups);
        if ($count === 0) {
            return $message;
        }

        $diff   = '0';
        $posted = false;
        foreach ($this->lastTrueups as $t) {
            $diff   = bcadd($diff, (string) $t->difference, 2);
            $posted = $posted || $t->affects_posted_months;
        }

        $message .= " This rate is back-dated: past daily interest on {$count} facilit".($count === 1 ? 'y' : 'ies')
            ." was re-priced (net change {$diff}) and a true-up record was kept.";
        if ($posted) {
            $message .= ' Interest for some of those months was already posted from the bank statement — compare it with the new theoretical figure.';
        }

        return $message;
    }

    public function updateCorridorRate(int $companyId, array $data, User $actor): CbeCorridorRate
    {
        $this->lastTrueups = [];

        return DB::transaction(function () use ($companyId, $data, $actor) {

            // 1. Get previous rate
            $previous = CbeCorridorRate::current($companyId); // this company's own rate

            // 2. Determine direction
            $newRate  = $data['rate'];
            $prevRate = $previous?->rate ?? 0;
            $direction = match(true) {
                bccomp((string) $newRate, (string) $prevRate, 4) > 0 => 'increase',
                bccomp((string) $newRate, (string) $prevRate, 4) < 0 => 'decrease',
                default                                               => 'unchanged',
            };

            // 3. Save the corridor rate record
            $corridorRate = CbeCorridorRate::create([
                'company_id'     => $companyId,
                'rate'           => $newRate,
                'previous_rate'  => $prevRate,
                'effective_date' => $data['effective_date'],
                'direction'      => $direction,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $actor->id,
            ]);

            // 4. Work out what this rate governs (Audit M24).
            //    $isLatest — no other rate is already in force from a
            //    later date, so this one is the facility's rate today.
            //    $rangeEnd — the next rate's effective date (exclusive);
            //    days from there on belong to that rate, not this one.
            $effective = substr((string) $data['effective_date'], 0, 10);
            $today     = now()->toDateString();

            $others = CbeCorridorRate::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', '!=', $corridorRate->id);

            $isLatest = ! (clone $others)->where('effective_date', '>', $effective)
                ->where('effective_date', '<=', $today)->exists();
            $rangeEnd = (clone $others)->where('effective_date', '>', $effective)->min('effective_date');
            $rangeEnd = $rangeEnd ? substr((string) $rangeEnd, 0, 10) : null;

            // 5. Find all active facilities linked to corridor rate
            $facilities = CreditFacility::where('company_id', $companyId)
                ->where('follows_corridor_rate', true)
                ->where('status', 'active')
                ->get();

            foreach ($facilities as $facility) {
                $before = $facility->toArray();
                $oldInterestRate = (string) $facility->interest_rate;

                $newInterestRate = bcadd(
                    (string) $newRate,
                    (string) $facility->bank_margin_rate,
                    4
                );

                // Only the latest rate in force sets the facility's
                // current rate.
                if ($isLatest) {
                    $facility->update([
                        'borrowing_rate' => $newRate,
                        'interest_rate'  => $newInterestRate,
                    ]);
                }

                // 6. Re-price the days this rate governs, and keep a record
                //    of what that did to the past.
                $trueup = $this->recalculateDailyInterest(
                    $companyId,
                    $facility->fresh(),
                    $effective,
                    $rangeEnd,
                    (string) $newInterestRate,
                    $oldInterestRate,
                    $corridorRate,
                    $actor,
                );
                if ($trueup) {
                    $this->lastTrueups[] = $trueup;
                }

                AuditLog::record(
                    $actor,
                    'credit_facility.corridor_rate_updated',
                    $facility,
                    $before,
                    $facility->fresh()->toArray()
                );
            }

            return $corridorRate;
        });
    }

    // ── Recalculate daily interest from effective date ─────────────

    /**
     * Re-prices daily_interest_amount for the days from $effectiveDate
     * up to (not including) $rangeEnd — or to the end of the history
     * when no later rate exists — using the new rate. Days before
     * $effectiveDate keep their old interest (Option A — daily split).
     * 360-day convention, bcmath throughout.
     *
     * Returns a FacilityRateTrueup when days that are already in the
     * PAST changed value (a back-dated rate); null otherwise.
     */
    private function recalculateDailyInterest(
        int $companyId,
        CreditFacility $facility,
        string $effectiveDate,
        ?string $rangeEnd,
        string $newAnnualRate,
        string $oldAnnualRate,
        CbeCorridorRate $corridorRate,
        User $actor,
    ): ?FacilityRateTrueup {
        $today       = now()->toDateString();
        $rateDecimal = bcdiv($newAnnualRate, '100', 10);

        $oldTotal = '0';
        $newTotal = '0';
        $months   = [];
        $lastDay  = null;

        $query = FacilityDailyBalance::where('company_id', $companyId)
            ->where('credit_facility_id', $facility->id)
            ->where('balance_date', '>=', $effectiveDate)
            ->when($rangeEnd, fn ($q) => $q->where('balance_date', '<', $rangeEnd))
            ->orderBy('balance_date');

        $query->get()->each(function (FacilityDailyBalance $row) use ($rateDecimal, $today, &$oldTotal, &$newTotal, &$months, &$lastDay) {
            // daily_interest = closing_balance × (rate / 100) / 360
            $daily = bcdiv(bcmul((string) $row->closing_balance, $rateDecimal, 10), '360', 6);

            $day = substr($row->balance_date->format('Y-m-d'), 0, 10);
            if ($day < $today) {
                $oldTotal = bcadd($oldTotal, (string) $row->daily_interest_amount, 6);
                $newTotal = bcadd($newTotal, $daily, 6);
                $months[substr($day, 0, 7)] = true;
                $lastDay = $day;
            }

            $row->update(['daily_interest_amount' => $daily]);
        });

        $difference = bcsub($newTotal, $oldTotal, 6);
        if ($lastDay === null || bccomp($difference, '0', 6) === 0) {
            return null;
        }

        $posted = '0.00';
        foreach (array_keys($months) as $ym) {
            $posted = bcadd($posted, FacilityLiabilityCharge::postedInterestForMonth($facility->id, $ym), 2);
        }

        $trueup = FacilityRateTrueup::create([
            'company_id'            => $companyId,
            'credit_facility_id'    => $facility->id,
            'cbe_corridor_rate_id'  => $corridorRate->id,
            'from_date'             => $effectiveDate,
            'to_date'               => $lastDay,
            'old_interest_rate'     => $oldAnnualRate,
            'new_interest_rate'     => $newAnnualRate,
            'old_theoretical'       => $oldTotal,
            'new_theoretical'       => $newTotal,
            'difference'            => $difference,
            'posted_interest'       => $posted,
            'affects_posted_months' => bccomp($posted, '0', 2) > 0,
            'created_by'            => $actor->id,
        ]);

        AuditLog::record($actor, 'facility_rate_trueup.recorded', $trueup, null, $trueup->toArray());

        return $trueup;
    }
}
