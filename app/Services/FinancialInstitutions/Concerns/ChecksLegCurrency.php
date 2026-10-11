<?php

namespace App\Services\FinancialInstitutions\Concerns;

// ══════════════════════════════════════════════════════════════════
//  ChecksLegCurrency
//
//  Audit M28 (Oct 2026) — shared by BuySellCurrencyService and
//  InternalMoneyTransferService. Each money movement names a "from"
//  and a "to" account (bank or safe). Nothing used to check that the
//  account really holds the currency being moved, so USD could be
//  "taken out of" an EGP account and the books would still balance —
//  with the foreign-currency columns quietly wrong.
//
//  assertLegCurrency() refuses the movement when a bank account's
//  currency, or a safe's, is not the currency the movement says it
//  moves. A safe account with no currency set is an Egyptian-pound
//  (functional-currency) safe.
//
//  Also: assertRateWithinTolerance() — a hand-typed exchange rate must
//  stay within MAX_RATE_DEVIATION_PERCENT of the system rate for that
//  date. Larger gaps are almost always a typing error, and a free rate
//  is a free gain/loss.
//
//  @layer    Service (trait)
//  @depends  BankAccount, ChartOfAccount, Currency, FxService
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Services\Accounting\FxService;

trait ChecksLegCurrency
{
    /** A typed rate may differ from the system rate by at most this many percent. */
    protected const MAX_RATE_DEVIATION_PERCENT = '5';

    /**
     * @param string   $side            'from' | 'to' — only used in the message
     * @param string   $legType         'bank' | 'safe' (any other leg type is skipped)
     */
    protected function assertLegCurrency(int $companyId, string $side, string $legType, ?int $bankAccountId, ?int $safeAccountId, int $expectedCurrencyId): void
    {
        $fx = app(FxService::class);

        if ($legType === 'bank' && $bankAccountId) {
            $account = BankAccount::withoutGlobalScopes()->where('company_id', $companyId)->find($bankAccountId);
            if (! $account) {
                return; // the leg resolver reports a missing account with its own message
            }
            $actual = $account->currency_id ? (int) $account->currency_id : $fx->functionalCurrencyId($companyId);
            $name   = $account->account_name ?: 'bank account';
        } elseif ($legType === 'safe' && $safeAccountId) {
            $account = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->find($safeAccountId);
            if (! $account) {
                return;
            }
            $actual = $account->currency_id ? (int) $account->currency_id : $fx->functionalCurrencyId($companyId);
            $name   = $account->name_en ?: 'safe';
        } else {
            return;
        }

        if ($actual !== null && (int) $actual !== $expectedCurrencyId) {
            $have = Currency::withoutGlobalScopes()->whereKey($actual)->value('code') ?? "#{$actual}";
            $want = Currency::withoutGlobalScopes()->whereKey($expectedCurrencyId)->value('code') ?? "#{$expectedCurrencyId}";

            throw new \DomainException(
                "The {$side} account \"{$name}\" holds {$have}, but this movement is in {$want}. Pick an account in {$want}."
            );
        }
    }

    /**
     * Refuses a typed rate that is more than MAX_RATE_DEVIATION_PERCENT
     * away from the system rate. No system rate on file → nothing to
     * compare against, so the typed rate stands.
     */
    protected function assertRateWithinTolerance(string $typedRate, ?string $systemRate, string $label): void
    {
        if ($systemRate === null || bccomp($systemRate, '0', 6) <= 0) {
            return;
        }

        $diff = ltrim(bcsub($typedRate, $systemRate, 10), '-');
        $pct  = bcmul(bcdiv($diff, $systemRate, 10), '100', 6);

        if (bccomp($pct, self::MAX_RATE_DEVIATION_PERCENT, 6) > 0) {
            throw new \DomainException(
                "The {$label} rate {$typedRate} is {$pct}% away from the system rate {$systemRate} — more than the allowed ".
                self::MAX_RATE_DEVIATION_PERCENT.'%. Check the figure, or add the correct rate under Settings → Currencies.'
            );
        }
    }
}
