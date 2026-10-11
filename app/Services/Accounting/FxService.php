<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FxService
//
//  ONE place that answers the three foreign-currency questions every
//  posting module needs (Audit C8, Sept 2026):
//
//    1. Is this document in the company's functional currency?
//         isFunctional($companyId, $currencyId)
//    2. What is the exchange rate for it on this date?
//         rateOn($companyId, $currencyId, $date)
//       Rule (already documented on ExchangeRate): the most recent
//       rate with rate_date <= the document date, from Settings >
//       Currencies. Functional currency = 1 exactly. No rate on file
//       → the posting is REFUSED with a clear message. It never
//       silently falls back to 1, which is exactly what used to book
//       USD 1,000 as EGP 1,000.
//       resolveRate() does the same but lets a rate typed on the
//       document itself win (> 0) over the rate table.
//    3. How much is that in functional currency?
//         toFunctional($amount, $rate)   → bcmath, rounded to 0.01
//
//  Plus the settlement helper every "booked at one rate, settled at
//  another" flow needs — cheques, LC/LG payments, loan instalments:
//
//         fxDifferenceLine($companyId, $bookedFunctional,
//                          $settledFunctional, $side, $description)
//
//  returns the balancing line to 431 Realized FX Gain or 571
//  Realized FX Loss (the same two accounts CustomerPaymentService and
//  SupplierPaymentService already use), or null when there is no
//  difference.
//
//  Plus tagLines() (Audit H12 / H15) — stamps a document's currency
//  and rate onto its journal lines so foreign-currency accounts keep
//  an exact foreign balance (see ForeignCurrencyLedgerService).
//
//  bcmath throughout (Rule 4). Stateless; safe to call from anywhere.
//
//  @layer    Service
//  @depends  ExchangeRate, CompanyActiveCurrency, Currency, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;

class FxService
{
    public const CODE_FX_GAIN = '431';
    public const CODE_FX_LOSS = '571';

    /** @var array<int, int|null> per-request cache */
    private array $functionalCache = [];

    public function functionalCurrencyId(int $companyId): ?int
    {
        if (! array_key_exists($companyId, $this->functionalCache)) {
            $id = CompanyActiveCurrency::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_functional', true)
                ->value('currency_id');

            // Fallback for a company missing its functional-currency row
            // (see BackfillFunctionalCurrencies): its base_currency code.
            if (! $id) {
                $code = \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('base_currency');
                $id = $code
                    ? Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', $code)->value('id')
                    : null;
            }

            $this->functionalCache[$companyId] = $id ? (int) $id : null;
        }

        return $this->functionalCache[$companyId];
    }

    /**
     * True when $currencyId is the company's functional currency — or
     * when no currency is given at all (null = functional, the
     * convention every older table in this app already follows).
     */
    public function isFunctional(int $companyId, ?int $currencyId): bool
    {
        if (! $currencyId) {
            return true;
        }

        $functional = $this->functionalCurrencyId($companyId);

        return $functional !== null && $functional === (int) $currencyId;
    }

    /**
     * Rate to functional currency on $date. '1' for functional.
     * Throws \DomainException when a foreign rate is missing.
     */
    public function rateOn(int $companyId, ?int $currencyId, string $date): string
    {
        if ($this->isFunctional($companyId, $currencyId)) {
            return '1';
        }

        $rate = ExchangeRate::rateFor($companyId, (int) $currencyId, substr($date, 0, 10));

        if ($rate === null || bccomp($rate, '0', 6) <= 0) {
            $code = Currency::withoutGlobalScopes()->whereKey($currencyId)->value('code') ?? "#{$currencyId}";
            throw new \DomainException(
                "No exchange rate for {$code} on or before ".substr($date, 0, 10).
                ' — add it in Settings › Currencies, then try again.'
            );
        }

        return $rate;
    }

    /**
     * The document's own typed rate if it has one (> 0), otherwise the
     * rate table on $date. Functional currency is always '1', whatever
     * was typed.
     */
    public function resolveRate(int $companyId, ?int $currencyId, string $date, mixed $documentRate = null): string
    {
        if ($this->isFunctional($companyId, $currencyId)) {
            return '1';
        }

        if ($documentRate !== null && $documentRate !== '' && is_numeric($documentRate) && bccomp((string) $documentRate, '0', 6) > 0) {
            return (string) $documentRate;
        }

        return $this->rateOn($companyId, $currencyId, $date);
    }

    /** amount × rate, rounded half-up to 2 decimals. */
    public function toFunctional(mixed $amount, mixed $rate): string
    {
        return $this->round2(bcmul((string) ($amount ?? '0'), (string) ($rate ?? '1'), 8));
    }

    /**
     * Balancing line for the difference between what a balance was
     * BOOKED at and what it was SETTLED at, both in functional.
     *
     * $settledSide says which side the settled amount sits on in the
     * entry being built:
     *   'credit' — money going OUT (e.g. Cr Bank settles Dr 222): the
     *              entry debits the booked amount and credits the
     *              settled amount; settled > booked is a LOSS.
     *   'debit'  — money coming IN (e.g. Dr Bank settles Cr 134):
     *              settled > booked is a GAIN.
     *
     * Returns a journal line array, or null when there is no difference.
     */
    public function fxDifferenceLine(int $companyId, string $bookedFunctional, string $settledFunctional, string $settledSide, string $description): ?array
    {
        $diff = bcsub($settledFunctional, $bookedFunctional, 2);

        if (bccomp($diff, '0', 2) === 0) {
            return null;
        }

        $abs = ltrim($diff, '-');
        $isGain = $settledSide === 'debit'
            ? bccomp($diff, '0', 2) > 0
            : bccomp($diff, '0', 2) < 0;

        $account = $this->account($companyId, $isGain ? self::CODE_FX_GAIN : self::CODE_FX_LOSS);

        return [
            'chart_of_account_id' => $account->id,
            'debit'               => $isGain ? '0' : $abs,
            'credit'              => $isGain ? $abs : '0',
            'description'         => ($isGain ? 'Realized FX Gain — ' : 'Realized FX Loss — ').$description,
        ];
    }

    /**
     * Audit H12 / H15 — stamps the document's currency and the rate it
     * converted at onto journal lines, so JournalEntryService records
     * the exact foreign amount on any foreign-currency account the
     * entry touches (bank, safe, facility…). Lines that already carry
     * a currency_id are left alone; lines on main-currency accounts
     * simply ignore the stamp. See ForeignCurrencyLedgerService.
     */
    public function tagLines(array $lines, ?int $currencyId, mixed $rate): array
    {
        if (! $currencyId || $rate === null || $rate === '' || ! is_numeric($rate) || bccomp((string) $rate, '0', 6) <= 0) {
            return $lines;
        }

        foreach ($lines as $i => $line) {
            if (! isset($line['currency_id'])) {
                $lines[$i]['currency_id']   = $currencyId;
                $lines[$i]['exchange_rate'] = (string) $rate;
            }
        }

        return $lines;
    }

    private function account(int $companyId, string $code): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} (Realized FX ".($code === self::CODE_FX_GAIN ? 'Gain' : 'Loss').') — check the seeder.');
    }

    private function round2(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $offset, 2);
    }
}
