<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  BackfillForeignAmounts
//
//  One-off console command for Audit H12 / H15 (Sept 2026). Migration
//  000299 gave journal lines a foreign amount (amount_currency). New
//  postings fill it automatically; this command fills it for lines
//  posted BEFORE the fix, on every single-foreign-currency account
//  (USD bank, EUR loan… — see ForeignCurrencyLedgerService).
//
//  How each old line is valued:
//    • The opening-balance entry → the foreign amount typed on the
//      Opening Balances screen for that account (exact).
//    • Every other line → EGP amount ÷ the rate-table rate for the
//      entry date. This is exact whenever the document used the
//      rate-table rate (the default everywhere since Audit C8). A
//      document posted with a hand-typed rate may be off by the
//      difference — review the account on the FX Revaluation preview
//      before the first run.
//    • No rate on file for that date → left empty and counted; the
//      dashboards and the FX Revaluation screen mark the account as
//      "foreign balance incomplete" until a rate is added and this
//      command is run again.
//
//  Safe to re-run: only lines still empty are touched.
//
//  Usage:
//    php artisan fx:backfill-foreign-amounts --dry-run
//    php artisan fx:backfill-foreign-amounts
//    php artisan fx:backfill-foreign-amounts --company=5
//
//  @layer    Console Command
//  @depends  ForeignCurrencyLedgerService, ExchangeRate, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Services\Accounting\ForeignCurrencyLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillForeignAmounts extends Command
{
    protected $signature = 'fx:backfill-foreign-amounts {--company= : Only this company id} {--dry-run : Report only, change nothing}';

    protected $description = 'Fills the foreign amount on old journal lines of foreign-currency accounts (Audit H12/H15).';

    public function handle(ForeignCurrencyLedgerService $ledger): int
    {
        $companyIds = DB::table('companies')
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->pluck('id');

        $dry = (bool) $this->option('dry-run');

        foreach ($companyIds as $companyId) {
            $companyId = (int) $companyId;
            $map = $ledger->accountCurrencyMap($companyId);
            if ($map === []) continue;

            $filled = 0;
            $missing = 0;

            $lines = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $companyId)
                ->whereIn('l.chart_of_account_id', array_keys($map))
                ->whereNull('l.amount_currency')
                ->get(['l.id', 'l.chart_of_account_id', 'l.debit', 'l.credit', 'e.entry_date', 'e.source_type']);

            $obForeign = DB::table('ob_coa_balances')
                ->where('company_id', $companyId)
                ->whereNotNull('amount_foreign')
                ->get(['account_id', 'currency_id', 'amount_foreign', 'exchange_rate'])
                ->keyBy('account_id');

            $rateCache = [];

            foreach ($lines as $line) {
                $currencyId = (int) $map[$line->chart_of_account_id];
                $functional = bcsub((string) $line->debit, (string) $line->credit, 2);
                $sign = bccomp($functional, '0', 2) < 0 ? '-1' : '1';
                $native = null;
                $rate = null;

                $ob = $obForeign->get($line->chart_of_account_id);
                if ($line->source_type === JournalEntry::SOURCE_OPENING_BALANCE && $ob && (int) $ob->currency_id === $currencyId) {
                    $native = bcmul(ltrim((string) $ob->amount_foreign, '-'), $sign, 2);
                    $rate = $ob->exchange_rate;
                } else {
                    $key = $currencyId.'|'.$line->entry_date;
                    if (! array_key_exists($key, $rateCache)) {
                        $rateCache[$key] = ExchangeRate::rateFor($companyId, $currencyId, substr((string) $line->entry_date, 0, 10));
                    }
                    $rate = $rateCache[$key];
                    if ($rate !== null && bccomp($rate, '0', 6) > 0) {
                        $raw = bcdiv($functional, $rate, 8);
                        $native = bcadd($raw, str_starts_with($raw, '-') ? '-0.005' : '0.005', 2);
                    }
                }

                if ($native === null) {
                    $missing++;
                    continue;
                }

                $filled++;
                if (! $dry) {
                    DB::table('journal_entry_lines')->where('id', $line->id)->update([
                        'currency_id'     => $currencyId,
                        'amount_currency' => $native,
                        'exchange_rate'   => $rate,
                    ]);
                }
            }

            $this->line("Company #{$companyId}: ".count($map).' foreign-currency account(s), '
                .($dry ? 'would fill ' : 'filled ')."{$filled} line(s)"
                .($missing ? ", {$missing} line(s) have no exchange rate on file for their date — add the rates in Settings › Currencies and run again." : '.'));

            $mixed = $ledger->mixedAccounts($companyId);
            if ($mixed !== []) {
                $this->warn('  Accounts linked to more than one currency (not tracked, not revalued): #'.implode(', #', $mixed));
            }
        }

        return self::SUCCESS;
    }
}
