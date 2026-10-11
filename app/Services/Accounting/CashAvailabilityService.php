<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashAvailabilityService
//
//  Guards every place money leaves a bank current account or the
//  cash safe: refuses a payment that would push either balance
//  negative. Built in response to a real Trial Balance showing both
//  bank accounts and the safe deeply negative — nothing in the app
//  was checking available funds before posting a withdrawal.
//
//  Deliberately separate from the OD-facility "available room" check
//  (FacilitySettlementEngine::recordWithdrawal() already enforces
//  that one, correctly, and has since before this service existed —
//  a facility's outstanding_balance is tracked on the facility row
//  itself, a different mechanism from a bank/safe account's balance,
//  which only ever exists as the live sum of its own posted journal
//  lines). Someone who needs more cash than a bank account holds is
//  expected to explicitly choose the credit facility as the payment
//  source instead — this service does not auto-overdraft a current
//  account into its facility; it just refuses the payment outright.
//
//  Reuses LedgerStatementService::totals() — the exact same
//  ending-balance calculation the Trial Balance and every Account
//  Statement report already use — so this check and what a user
//  sees on those reports can never silently disagree with each
//  other.
//
//  "Available balance" here means: every posted journal entry
//  against this account's own chart_of_accounts leaf, up through
//  today's real date — regardless of the new transaction's own
//  (possibly backdated) date. That's deliberate: a backdated bill
//  payment entered today still spends real money today, against
//  whatever the account actually holds today, not against a
//  historical snapshot from the payment's own date.
//
//  @layer    Service
//  @depends  LedgerStatementService, ChartOfAccount, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;

class CashAvailabilityService
{
    public function __construct(private LedgerStatementService $ledger) {}

    /**
     * Throws if paying `$amount` out of this bank account would push
     * its balance negative.
     */
    public function assertBankAccountCanCover(int $companyId, BankAccount $account, string $amount): void
    {
        $this->assertAccountCanCover($companyId, $account->coa_account_id, $amount, $account->account_name);
    }

    /**
     * Throws if paying `$amount` out of this cash safe (a plain
     * Chart of Accounts leaf under 131 Cash — there is no separate
     * "Safe" model, see settings/payment forms) would push its
     * balance negative.
     */
    public function assertSafeCanCover(int $companyId, ChartOfAccount $safeAccount, string $amount): void
    {
        $this->assertAccountCanCover($companyId, $safeAccount->id, $amount, $safeAccount->name_en);
    }

    /**
     * Generic form for callers that have already resolved a leg down
     * to a plain Chart of Accounts row (e.g. InternalMoneyTransferService,
     * which resolves both bank and safe legs through the same
     * resolveLegAccount() before this point) and don't have a
     * BankAccount model handy to pass through assertBankAccountCanCover().
     */
    public function assertAccountCanCover(int $companyId, int $coaAccountId, string $amount, string $accountLabel): void
    {
        $balance = $this->currentBalance($companyId, $coaAccountId);

        if (bccomp($amount, $balance, 2) > 0) {
            throw new \DomainException(
                "Insufficient funds in {$accountLabel} — available balance is ".
                Money::display($balance).' but this transaction needs '.
                Money::display($amount).'.'
            );
        }
    }

    private function currentBalance(int $companyId, int $coaAccountId): string
    {
        $totals = $this->ledger->totals(
            companyId:   $companyId,
            coaAccountId: $coaAccountId,
            startDate:   null,
            endDate:     null, // defaults to today inside totals()
            balanceSign: LedgerStatementService::SIGN_ASSET,
        );

        return (string) ($totals['ending_balance'] ?? '0.00');
    }
}
