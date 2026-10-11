<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ForeignCurrencyLedgerService
//
//  Audit H12 + H15 (Sept 2026). Knows, for any account in the ledger,
//  "which ONE foreign currency does this account hold?", and keeps
//  the foreign amount of every line on such an account
//  (journal_entry_lines.amount_currency — migration 000299).
//
//  WHICH ACCOUNTS ARE "FOREIGN-CURRENCY ACCOUNTS"
//  An account holds a single foreign currency when any of these say
//  so, and none of them disagree:
//    • chart_of_accounts.currency_id      (cash / bank sub-accounts)
//    • bank_accounts.currency_id          (current, time deposit,
//                                          cash-cover accounts)
//    • credit_facilities.currency_id      (overdrafts, loans, LG/LC)
//    • leasing_contracts.currency_id      (lease liability account)
//  An account linked to two different currencies (e.g. one COA
//  account shared by a USD and a EUR bank account) is treated as
//  MIXED and never gets a foreign amount — revaluing it would be a
//  guess. The FX Revaluation screen lists such accounts so they can
//  be split. Accounts in the main currency are never tagged.
//
//  Multi-currency control accounts — AR 133002 and AP 221002 hold
//  USD, EUR… together — are NOT tagged here. Their foreign balances
//  are known invoice by invoice (FxRevaluationService revalues the
//  open invoices and bills directly).
//
//  HOW A LINE GETS ITS FOREIGN AMOUNT (enrichLines, called by
//  JournalEntryService for every line it writes)
//    1. The posting module states it: 'currency_id' + 'amount_currency'
//       on the line (exact — e.g. the USD received on a collection).
//       A revaluation line states amount_currency = 0: the EGP value
//       changes, the USD balance does not.
//    2. The posting module states the rate it used: 'currency_id' +
//       'exchange_rate' → EGP amount ÷ that rate.
//    3. Otherwise → EGP amount ÷ the rate-table rate for the entry
//       date (Settings › Currencies; the same rule FxService uses).
//    4. No rate on file → amount_currency stays NULL ("not known").
//       The posting is NOT blocked; the dashboards and the
//       revaluation screen flag the account as incomplete instead.
//
//  bcmath throughout (Rule 4). Every query filters company_id
//  explicitly (Audit H9) — safe from jobs and commands.
//
//  @layer    Service
//  @depends  ChartOfAccount, BankAccount, CreditFacility,
//            LeasingContract, ExchangeRate, FxService, JournalEntryLine
// ══════════════════════════════════════════════════════════════════

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

class ForeignCurrencyLedgerService
{
    public function __construct(private FxService $fx) {}

    /**
     * [chart_of_account_id => currency_id] for every account of this
     * company that holds exactly one FOREIGN currency. Pass $accountIds
     * to limit the lookup (used on every posting — keeps it cheap).
     */
    public function accountCurrencyMap(int $companyId, ?array $accountIds = null): array
    {
        [$map] = $this->resolve($companyId, $accountIds);

        return $map;
    }

    /** Account ids linked to more than one currency — never tagged. */
    public function mixedAccounts(int $companyId): array
    {
        [, $mixed] = $this->resolve($companyId, null);

        return $mixed;
    }

    /**
     * Adds currency_id / amount_currency / exchange_rate to each line.
     * Lines on accounts that are not single-foreign-currency accounts
     * get NULLs. See the class header for the four rules.
     */
    public function enrichLines(int $companyId, string $entryDate, array $lines): array
    {
        $ids = array_values(array_unique(array_map(fn ($l) => (int) $l['chart_of_account_id'], $lines)));
        $map = $this->accountCurrencyMap($companyId, $ids);
        $date = substr($entryDate, 0, 10);
        $tableRates = [];

        foreach ($lines as $i => $line) {
            $accountCurrency = $map[(int) $line['chart_of_account_id']] ?? null;

            if (! $accountCurrency) {
                $lines[$i]['currency_id'] = null;
                $lines[$i]['amount_currency'] = null;
                $lines[$i]['exchange_rate'] = null;
                continue;
            }

            $functional = bcsub((string) ($line['debit'] ?? '0'), (string) ($line['credit'] ?? '0'), 2);
            $sign = bccomp($functional, '0', 2) < 0 ? '-1' : '1';
            $statedCurrency = isset($line['currency_id']) ? (int) $line['currency_id'] : null;
            $sameCurrency = $statedCurrency === (int) $accountCurrency;

            $native = null;
            $rate = null;

            if ($sameCurrency && isset($line['amount_currency']) && $line['amount_currency'] !== '') {
                // Rule 1 — stated by the posting module (unsigned, same side as the EGP amount).
                $native = bcmul(ltrim((string) $line['amount_currency'], '-'), $sign, 2);
                $rate = isset($line['exchange_rate']) && bccomp((string) $line['exchange_rate'], '0', 6) > 0
                    ? (string) $line['exchange_rate']
                    : (bccomp($native, '0', 2) !== 0 ? bcdiv(ltrim($functional, '-'), ltrim($native, '-'), 6) : null);
            } elseif ($sameCurrency && isset($line['exchange_rate']) && bccomp((string) $line['exchange_rate'], '0', 6) > 0) {
                // Rule 2 — the rate the posting module used.
                $rate = (string) $line['exchange_rate'];
                $native = $this->round2(bcdiv($functional, $rate, 8));
            } else {
                // Rule 3 — rate table on the entry date; Rule 4 — none on file.
                if (! array_key_exists($accountCurrency, $tableRates)) {
                    $tableRates[$accountCurrency] = ExchangeRate::rateFor($companyId, (int) $accountCurrency, $date);
                }
                $tableRate = $tableRates[$accountCurrency];
                if ($tableRate !== null && bccomp($tableRate, '0', 6) > 0) {
                    $rate = $tableRate;
                    $native = $this->round2(bcdiv($functional, $rate, 8));
                }
            }

            $lines[$i]['currency_id'] = (int) $accountCurrency;
            $lines[$i]['amount_currency'] = $native;
            $lines[$i]['exchange_rate'] = $rate;
        }

        return $lines;
    }

    /**
     * Balances of foreign-currency accounts as of a date (inclusive):
     *   [coa_id => ['currency_id', 'native', 'functional', 'unknown_lines']]
     * 'native' is the foreign balance, 'functional' the EGP book
     * balance, both signed debit − credit. 'unknown_lines' counts
     * lines whose foreign amount is not known (Rule 4) — when > 0 the
     * native figure is incomplete and must be shown as such.
     */
    public function balances(int $companyId, array $coaIds, string $asOfDate): array
    {
        $map = $this->accountCurrencyMap($companyId, $coaIds);
        if ($map === []) return [];

        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)
            ->whereIn('l.chart_of_account_id', array_keys($map))
            ->where('e.entry_date', '<=', substr($asOfDate, 0, 10))
            ->groupBy('l.chart_of_account_id')
            ->selectRaw('l.chart_of_account_id as coa_id,
                COALESCE(SUM(l.debit - l.credit), 0) as functional,
                COALESCE(SUM(l.amount_currency), 0) as native,
                SUM(CASE WHEN l.amount_currency IS NULL AND (l.debit <> 0 OR l.credit <> 0) THEN 1 ELSE 0 END) as unknown_lines')
            ->get()
            ->keyBy('coa_id');

        $out = [];
        foreach ($map as $coaId => $currencyId) {
            $row = $rows->get($coaId);
            $out[$coaId] = [
                'currency_id'   => (int) $currencyId,
                'native'        => bcadd((string) ($row->native ?? '0'), '0', 2),
                'functional'    => bcadd((string) ($row->functional ?? '0'), '0', 2),
                'unknown_lines' => (int) ($row->unknown_lines ?? 0),
            ];
        }

        return $out;
    }

    /** One account's balance — null when the account is not a foreign-currency account. */
    public function balance(int $companyId, int $coaId, string $asOfDate): ?array
    {
        return $this->balances($companyId, [$coaId], $asOfDate)[$coaId] ?? null;
    }

    // ── Private ──────────────────────────────────────────────────

    /** @return array{0: array<int,int>, 1: array<int>} [map, mixed account ids] */
    private function resolve(int $companyId, ?array $accountIds): array
    {
        $functionalId = $this->fx->functionalCurrencyId($companyId);
        $links = []; // coa_id => [currency_id => true]

        $add = function ($rows) use (&$links) {
            foreach ($rows as $r) {
                if ($r->coa_id && $r->currency_id) {
                    $links[(int) $r->coa_id][(int) $r->currency_id] = true;
                }
            }
        };

        $limit = fn ($q, string $col) => $accountIds === null ? $q : $q->whereIn($col, $accountIds ?: [0]);

        $add($limit(DB::table('chart_of_accounts')->where('company_id', $companyId)->whereNotNull('currency_id'), 'id')
            ->get(['id as coa_id', 'currency_id']));
        $add($limit(DB::table('bank_accounts')->where('company_id', $companyId)->whereNotNull('coa_account_id'), 'coa_account_id')
            ->get(['coa_account_id as coa_id', 'currency_id']));
        $add($limit(DB::table('credit_facilities')->where('company_id', $companyId)->whereNotNull('coa_account_id'), 'coa_account_id')
            ->get(['coa_account_id as coa_id', 'currency_id']));
        $add($limit(DB::table('leasing_contracts')->where('company_id', $companyId)->whereNotNull('bank_liability_account_id'), 'bank_liability_account_id')
            ->get(['bank_liability_account_id as coa_id', 'currency_id']));

        $map = [];
        $mixed = [];
        foreach ($links as $coaId => $currencies) {
            $ids = array_keys($currencies);
            if (count($ids) > 1) {
                $mixed[] = $coaId;
                continue;
            }
            if ($functionalId !== null && $ids[0] === $functionalId) {
                continue; // main-currency account — nothing to track
            }
            $map[$coaId] = $ids[0];
        }

        return [$map, $mixed];
    }

    private function round2(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $offset, 2);
    }
}
