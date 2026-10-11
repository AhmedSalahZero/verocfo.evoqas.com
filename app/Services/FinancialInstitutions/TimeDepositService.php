<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  TimeDepositService
//
//  Time Deposit Lifecycle (July 2026, confirmed with Mahmoud). Four
//  user-triggered actions on an active Time Deposit bank account —
//  none of these are ever automatic, same "user records it when it
//  actually happened" principle as Lease payments (Building Bible
//  Rule 29.7):
//
//    applyInterest() — periodic interest while the TD stays open.
//                      Principal untouched. Can happen many times
//                      over one TD's life (e.g. every 2 weeks).
//    breakOrMature() — early ('break') or on-schedule ('maturity')
//                      closure. Always the FULL td_amount (confirmed:
//                      no partial break) — principal + accumulated
//                      interest both paid out, account deactivated.
//    renew()         — TD stays open past maturity. Interest for the
//                      completed period is realized and paid out
//                      (same shape as applyInterest), and the TD's
//                      own maturity date / rate move forward to the
//                      new term. Principal untouched, account stays
//                      active. Replaces the old static td_auto_renewal
//                      flag — every renewal is now its own tracked,
//                      journaled event.
//
//  Every action writes one BankAccountTdEvent row (the permanent
//  history — see migration 000117) and posts exactly one balanced
//  journal entry through JournalEntryService, inside the same
//  transaction. Interest always posts to COA 423 (Interest &
//  Investment Income — renamed from "Rental Income" in the seeder as
//  part of this same engagement, see ChartOfAccountSeeder).
//
//  Funding/credit accounts are restricted to 'current' bank accounts
//  — a TD's cash always moves to/from an ordinary current account,
//  never another TD or an escrow account.
//
//  AUDIT C8 FIX (Sept 2026) — a USD (or other foreign) Time Deposit's
//  principal and interest were posted 1:1 into the EGP ledger. Every
//  amount is now converted at the TD currency's rate on the event date
//  (FxService — refuses if the rate is missing). The event rows still
//  record the TD-currency figures the user typed.
//
//  @layer    Service
//  @depends  BankAccount, BankAccountTdEvent, JournalEntryService, ChartOfAccount, FxService
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\BankAccountTdEvent;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Support\Facades\DB;

class TimeDepositService
{
    use \App\Services\FinancialInstitutions\Concerns\ChecksInterestVariance;

    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    // ── Apply Interest — periodic, principal untouched ─────────────

    public function applyInterest(BankAccount $td, array $data, User $actor): BankAccountTdEvent
    {
        $this->assertOpenTimeDeposit($td);
        $credit = $this->resolveCreditAccount($td->company_id, (int) $data['credit_bank_account_id']);
        $this->checkTdInterest($td, (string) $data['interest_amount'], (string) $data['event_date'], true);

        return DB::transaction(function () use ($td, $credit, $data, $actor) {
            [$wht, $whtRate] = $this->resolveWht($td, (string) $data['interest_amount'], $data['wht_amount'] ?? null);
            $entry = $this->postInterestEntry($td, $credit, (string) $data['interest_amount'], $data['event_date'], $actor, 'Time Deposit interest — '.$td->account_name, $wht);

            $event = BankAccountTdEvent::create([
                'company_id'              => $td->company_id,
                'bank_account_id'         => $td->id,
                'event_type'              => 'interest',
                'event_date'              => $data['event_date'],
                'interest_amount'         => $data['interest_amount'],
                'wht_rate'                => $whtRate,
                'wht_amount'              => $wht,
                'credit_bank_account_id'  => $credit->id,
                'journal_entry_id'        => $entry->id,
                'created_by'              => $actor->id,
            ]);

            // Fix: audit_log.action is capped at 30 chars — the original
            // 32-char string was a latent bug, never yet triggered live.
            AuditLog::record($actor, 'bank_account.td_interest', $td, null, $event->toArray());

            return $event->fresh(['creditBankAccount', 'journalEntry']);
        });
    }

    /** M-29: warn if interest differs >1% from principal × rate × days since start, less interest already paid. */
    private function checkTdInterest(BankAccount $td, string $typed, string $eventDate, bool $upperBoundOnly): void
    {
        if (! $td->td_start_date || ! $td->td_interest_rate) {
            return;
        }
        $days = (int) abs(\Carbon\Carbon::parse($td->td_start_date)->diffInDays(\Carbon\Carbon::parse($eventDate)));
        $paid = (string) (BankAccountTdEvent::where('bank_account_id', $td->id)->where('event_type', 'interest')->sum('interest_amount') ?: 0);
        $this->checkInterestVariance(bcadd($typed, $paid, 2), (string) $td->td_amount, (string) $td->td_interest_rate, $days, 'Time Deposit '.$td->account_name, $upperBoundOnly);
    }

    // ── Break / Maturity — always the full TD, account closes ──────

    public function breakOrMature(BankAccount $td, string $eventType, array $data, User $actor): BankAccountTdEvent
    {
        if (! in_array($eventType, ['break', 'maturity'], true)) {
            throw new \DomainException("Invalid Time Deposit closure type: {$eventType}.");
        }

        $this->assertOpenTimeDeposit($td);
        $credit = $this->resolveCreditAccount($td->company_id, (int) $data['credit_bank_account_id']);
        $this->checkTdInterest($td, (string) $data['interest_amount'], (string) $data['event_date'], false);

        return DB::transaction(function () use ($td, $eventType, $credit, $data, $actor) {
            $principal = (string) $td->td_amount;
            $interest  = (string) $data['interest_amount'];

            // C8: TD-currency amounts → functional at the event-date rate.
            $principalF = $this->toFunctional($td, $principal, (string) $data['event_date']);
            $interestF  = $this->toFunctional($td, $interest, (string) $data['event_date']);
            [$wht, $whtRate] = $this->resolveWht($td, $interest, $data['wht_amount'] ?? null);
            $whtF       = bccomp($wht, '0', 2) > 0 ? $this->toFunctional($td, $wht, (string) $data['event_date']) : '0.00';
            $totalF     = bcsub(bcadd($principalF, $interestF, 2), $whtF, 2); // cash received = principal + interest − tax withheld (M-33)

            $lines = [
                ['chart_of_account_id' => $credit->coa_account_id, 'debit' => $totalF, 'credit' => '0', 'description' => ucfirst($eventType)." — {$td->account_name}"],
                ['chart_of_account_id' => $td->coa_account_id,     'debit' => '0',     'credit' => $principalF, 'description' => 'Principal returned — '.$td->account_name],
            ];

            if (bccomp($interestF, '0', 2) > 0) {
                $interestCoa = ChartOfAccount::findByCode($td->company_id, '423')
                    ?? throw new \DomainException('Chart of Accounts is missing system account 423 (Interest & Investment Income) — check the seeder.');
                $lines[] = ['chart_of_account_id' => $interestCoa->id, 'debit' => '0', 'credit' => $interestF, 'description' => 'Interest — '.$td->account_name];
            }
            if (bccomp($whtF, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $this->whtAccount($td->company_id)->id, 'debit' => $whtF, 'credit' => '0', 'description' => 'Tax withheld on deposit interest — '.$td->account_name];
            }

            $entry = $this->journalEntryService->post(
                companyId: $td->company_id,
                entryDate: $data['event_date'],
                lines: $lines,
                sourceType: 'bank_account_td_event',
                sourceId: null,
                narration: ucfirst($eventType)." of Time Deposit {$td->account_name} ({$td->account_number})",
                actor: $actor,
            );

            $before = $td->toArray();
            $td->update(['is_active' => false]);

            $event = BankAccountTdEvent::create([
                'company_id'              => $td->company_id,
                'bank_account_id'         => $td->id,
                'event_type'              => $eventType,
                'event_date'              => $data['event_date'],
                'interest_amount'         => $interest,
                'wht_rate'                => $whtRate,
                'wht_amount'              => $wht,
                'principal_amount'        => $principal,
                'credit_bank_account_id'  => $credit->id,
                'journal_entry_id'        => $entry->id,
                'created_by'              => $actor->id,
            ]);

            AuditLog::record($actor, "bank_account.td_{$eventType}", $td, $before, $td->fresh()->toArray());

            return $event->fresh(['creditBankAccount', 'journalEntry']);
        });
    }

    // ── Renew — interest realized, term rolls forward ───────────────

    public function renew(BankAccount $td, array $data, User $actor): BankAccountTdEvent
    {
        $this->assertOpenTimeDeposit($td);
        $credit = $this->resolveCreditAccount($td->company_id, (int) $data['credit_bank_account_id']);

        return DB::transaction(function () use ($td, $credit, $data, $actor) {
            [$wht, $whtRate] = $this->resolveWht($td, (string) $data['interest_amount'], $data['wht_amount'] ?? null);
            $entry = $this->postInterestEntry($td, $credit, (string) $data['interest_amount'], $data['event_date'], $actor, 'Time Deposit renewal interest — '.$td->account_name, $wht);

            $oldMaturity = $td->td_maturity_date;
            $oldRate     = $td->td_interest_rate;

            $td->update([
                'td_maturity_date'  => $data['new_maturity_date'],
                'td_interest_rate'  => $data['new_interest_rate'],
            ]);

            $event = BankAccountTdEvent::create([
                'company_id'              => $td->company_id,
                'bank_account_id'         => $td->id,
                'event_type'              => 'renewal',
                'event_date'              => $data['event_date'],
                'interest_amount'         => $data['interest_amount'],
                'wht_rate'                => $whtRate,
                'wht_amount'              => $wht,
                'old_maturity_date'       => $oldMaturity,
                'new_maturity_date'       => $data['new_maturity_date'],
                'old_interest_rate'       => $oldRate,
                'new_interest_rate'       => $data['new_interest_rate'],
                'credit_bank_account_id'  => $credit->id,
                'journal_entry_id'        => $entry->id,
                'created_by'              => $actor->id,
            ]);

            AuditLog::record($actor, 'bank_account.td_renewed', $td, null, $event->toArray());

            return $event->fresh(['creditBankAccount', 'journalEntry']);
        });
    }

    // ── Private helpers ─────────────────────────────────────────────

    /** Audit C8: a TD-currency amount in functional currency on $date. */
    private function toFunctional(BankAccount $td, string $amount, string $date): string
    {
        $fx = app(\App\Services\Accounting\FxService::class);

        return $fx->toFunctional($amount, $fx->rateOn($td->company_id, $td->currency_id ? (int) $td->currency_id : null, substr($date, 0, 10)));
    }

    private function assertOpenTimeDeposit(BankAccount $td): void
    {
        if (! $td->isTimeDeposit()) {
            throw new \DomainException('This action only applies to Time Deposit accounts.');
        }
        if (! $td->is_active) {
            throw new \DomainException('This Time Deposit is already closed — no further action can be recorded against it.');
        }
    }

    /**
     * Rule 2.5 — cross-company FK validation. Restricted to 'current'
     * accounts: a TD's cash always moves to/from an ordinary current
     * account, never another TD or an escrow account.
     */
    private function resolveCreditAccount(int $companyId, int $creditBankAccountId): BankAccount
    {
        $account = BankAccount::where('id', $creditBankAccountId)
            ->where('company_id', $companyId)
            ->where('account_type', 'current')
            ->where('is_active', true)
            ->first();

        return $account ?? throw new \DomainException(
            'The selected account must be an active Current account belonging to this company.'
        );
    }

    /** Shared Dr [credit account] / Cr 423 posting used by applyInterest() and renew(). */
    /**
     * M-33: Egyptian banks withhold tax (20% unless set otherwise on the
     * deposit) from deposit interest before crediting the account. The
     * interest typed is GROSS; the withholding is a tax credit
     * (142 Debit WHT Receivable), so cash received = gross − WHT.
     * Returns [whtAmount (TD currency), whtRate].
     */
    private function resolveWht(BankAccount $td, string $grossInterest, ?string $typedWht): array
    {
        $rate = (string) ($td->td_wht_rate ?? '20');
        $calc = bcdiv(bcmul($grossInterest, $rate, 6), '100', 2);
        $wht  = ($typedWht === null || $typedWht === '') ? $calc : bcadd($typedWht, '0', 2);
        if (bccomp($wht, $grossInterest, 2) > 0) {
            throw new \DomainException('The tax withheld cannot be more than the interest itself.');
        }
        return [$wht, $rate];
    }

    private function whtAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '142')
            ?? throw new \DomainException('Chart of Accounts is missing system account 142 (Debit WHT Receivable) — check the seeder.');
    }

    private function postInterestEntry(BankAccount $td, BankAccount $credit, string $interestAmount, string $eventDate, User $actor, string $narration, string $whtAmount = '0')
    {
        $interestCoa = ChartOfAccount::findByCode($td->company_id, '423')
            ?? throw new \DomainException('Chart of Accounts is missing system account 423 (Interest & Investment Income) — check the seeder.');

        // C8: interest is in the TD's currency — convert before posting.
        $grossF = $this->toFunctional($td, $interestAmount, $eventDate);
        $whtF   = bccomp($whtAmount, '0', 2) > 0 ? $this->toFunctional($td, $whtAmount, $eventDate) : '0.00';
        $netF   = bcsub($grossF, $whtF, 2);

        $lines = [
            ['chart_of_account_id' => $credit->coa_account_id, 'debit' => $netF, 'credit' => '0', 'description' => $narration],
        ];
        if (bccomp($whtF, '0', 2) > 0) {
            $lines[] = ['chart_of_account_id' => $this->whtAccount($td->company_id)->id, 'debit' => $whtF, 'credit' => '0', 'description' => 'Tax withheld on deposit interest — '.$td->account_name];
        }
        $lines[] = ['chart_of_account_id' => $interestCoa->id, 'debit' => '0', 'credit' => $grossF, 'description' => $narration];

        return $this->journalEntryService->post(
            companyId: $td->company_id,
            entryDate: $eventDate,
            lines: $lines,
            sourceType: 'bank_account_td_event',
            sourceId: null,
            narration: $narration.' — '.$td->account_number,
            actor: $actor,
        );
    }
}
