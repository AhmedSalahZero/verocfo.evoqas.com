<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  CurrencyService
//
//  All business logic for the Currencies settings page.
//  Handles two concerns in one service:
//
//  1. Currency Activation
//     — List all system currencies with company activation status
//     — Toggle active/inactive per company
//     — Guard: functional currency cannot be deactivated
//     — Guard: currency with existing transactions cannot be deactivated
//       (Phase 2 — for now we allow deactivation with a warning)
//
//  2. Exchange Rate Management
//     — List all rates for a currency (newest first)
//     — Add a new rate (append-only — no update or delete)
//     — Resolve the correct rate for a given date
//     — Guard: duplicate rate for same currency + same date
//     — Bulk import from Excel/CSV (parse-then-confirm, same
//       two-step pattern as LeasingService's cheque import)
//
//  @layer    Service
//  @depends  App\Models\Currency
//            App\Models\CompanyActiveCurrency
//            App\Models\ExchangeRate
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CurrencyService
{
    // ── Currency List ─────────────────────────────────────────────

    /**
     * Returns:
     * 1. The functional currency record (for the banner)
     * 2. All other system currencies with activation status
     *
     * The functional currency is determined from company->base_currency.
     * It is NEVER shown in the activation grid — it is always active
     * by definition and has a fixed rate of 1.000000.
     */
    public function listWithStatus(int $companyId): array
    {
        // Get company's functional currency code
        $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);
        $functionalCode = $company?->base_currency ?? 'EGP';

        // All system currencies
        $allCurrencies = Currency::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // Separate functional from the rest
        $functionalCurrency = $allCurrencies->firstWhere('code', $functionalCode);
        $otherCurrencies    = $allCurrencies->where('code', '!=', $functionalCode)->values();

        // Company activations for non-functional currencies
        $activations = CompanyActiveCurrency::where('company_id', $companyId)
            ->get()
            ->keyBy('currency_id');

        // Latest rate per currency
        $latestRates = ExchangeRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('currency_id', $otherCurrencies->pluck('id'))
            ->orderByDesc('rate_date')
            ->get()
            ->groupBy('currency_id')
            ->map(fn ($rates) => $rates->first());

        // Merge activation status onto non-functional currencies
        $currencies = $otherCurrencies->map(function ($currency) use ($activations, $latestRates) {
            $activation = $activations->get($currency->id);
            $currency->is_activated     = (bool) $activation;
            $currency->is_company_active = $activation?->is_active ?? false;
            $currency->latest_rate      = $latestRates->get($currency->id);
            return $currency;
        });

        return [
            'functional' => $functionalCurrency,
            'currencies' => $currencies,
        ];
    }

    // ── Toggle Currency Activation ────────────────────────────────

    /**
     * Activates or deactivates a system currency for a company.
     * Creates an activation record if it doesn't exist.
     * Guards: functional currency cannot be deactivated.
     */
    public function toggleActivation(
        int    $companyId,
        int    $currencyId,
        User   $actor
    ): CompanyActiveCurrency {

        return DB::transaction(function () use ($companyId, $currencyId, $actor) {

            $activation = CompanyActiveCurrency::firstOrCreate(
                ['company_id' => $companyId, 'currency_id' => $currencyId],
                ['is_functional' => false, 'is_active' => false]
            );

            if ($activation->is_functional && $activation->is_active) {
                throw new \DomainException(
                    'The functional currency cannot be deactivated. Change the functional currency first.'
                );
            }

            $before = ['is_active' => $activation->is_active];
            $activation->update(['is_active' => ! $activation->is_active]);

            AuditLog::record($actor, 'toggle_currency', $activation, $before, ['is_active' => $activation->is_active]);

            return $activation;
        });
    }

    // ── Exchange Rates ────────────────────────────────────────────

    /**
     * Returns the LATEST rate per currency — used in "All currencies" view.
     * One row per currency. Uses DB join to bypass BelongsToCompany scope
     * on Currency (system currencies have company_id = NULL).
     */
    public function latestPerCurrency(int $companyId): Collection
    {
        return DB::table('exchange_rates as er')
            ->join('currencies as c', 'c.id', '=', 'er.currency_id')
            ->leftJoin('users as u', 'u.id', '=', 'er.created_by')
            ->where('er.company_id', $companyId)
            ->whereRaw('er.rate_date = (
                SELECT MAX(er2.rate_date)
                FROM exchange_rates er2
                WHERE er2.company_id = er.company_id
                  AND er2.currency_id = er.currency_id
            )')
            ->orderBy('c.code')
            ->select([
                'er.id', 'er.currency_id', 'er.rate', 'er.rate_date', 'er.created_by',
                'c.code as currency_code', 'c.name_en as currency_name_en',
                'c.name_ar as currency_name_ar', 'c.symbol as currency_symbol',
                'u.name as entered_by_name',
            ])
            ->get();
    }

    /**
     * Returns ALL rates for a specific currency — used in single currency view.
     * Optionally filtered by date range.
     * Uses DB join to bypass BelongsToCompany scope on Currency.
     */
    public function historyForCurrency(
        int     $companyId,
        int     $currencyId,
        ?string $dateFrom = null,
        ?string $dateTo   = null
    ): Collection {
        return DB::table('exchange_rates as er')
            ->join('currencies as c', 'c.id', '=', 'er.currency_id')
            ->leftJoin('users as u', 'u.id', '=', 'er.created_by')
            ->where('er.company_id', $companyId)
            ->where('er.currency_id', $currencyId)
            ->when($dateFrom, fn ($q) => $q->where('er.rate_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('er.rate_date', '<=', $dateTo))
            ->orderByDesc('er.rate_date')
            ->select([
                'er.id', 'er.currency_id', 'er.rate', 'er.rate_date', 'er.created_by',
                'c.code as currency_code', 'c.name_en as currency_name_en',
                'c.name_ar as currency_name_ar', 'c.symbol as currency_symbol',
                'u.name as entered_by_name',
            ])
            ->get();
    }

    /**
     * Returns ALL rates for export — full history all currencies.
     */
    public function allRatesForExport(int $companyId): Collection
    {
        return DB::table('exchange_rates as er')
            ->join('currencies as c', 'c.id', '=', 'er.currency_id')
            ->leftJoin('users as u', 'u.id', '=', 'er.created_by')
            ->where('er.company_id', $companyId)
            ->orderByDesc('er.rate_date')
            ->orderBy('c.code')
            ->select([
                'er.id', 'er.currency_id', 'er.rate', 'er.rate_date', 'er.created_by',
                'c.code as currency_code', 'c.name_en as currency_name_en',
                'c.name_ar as currency_name_ar', 'c.symbol as currency_symbol',
                'u.name as entered_by_name',
            ])
            ->get();
    }

    /**
     * Adds a new exchange rate for a currency.
     * Append-only — existing rates for the same date are rejected.
     * Rate stored to 6 decimal places using bcmath.
     */
    public function addRate(int $companyId, array $data, User $actor): ExchangeRate
    {
        // Guard: cannot add rate for functional currency
        $isFunctional = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('currency_id', $data['currency_id'])
            ->where('is_functional', true)
            ->exists();

        if ($isFunctional) {
            throw new \DomainException(
                'Exchange rate for the functional currency is always 1.000000 — no entry needed.'
            );
        }

        // Guard: duplicate rate for same currency + date
        $exists = ExchangeRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('currency_id', $data['currency_id'])
            ->where('rate_date', $data['rate_date'])
            ->exists();

        if ($exists) {
            throw new \DomainException(
                "A rate for this currency on {$data['rate_date']} already exists. Delete it first or choose a different date."
            );
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {

            $rate = ExchangeRate::create([
                'company_id'  => $companyId,
                'currency_id' => $data['currency_id'],
                'rate'        => bcadd((string) $data['rate'], '0', 6),
                'rate_date'   => $data['rate_date'],
                'source'      => ExchangeRate::SOURCE_MANUAL,
                'created_by'  => $actor->id,
                'created_at'  => now(),
            ]);

            AuditLog::record($actor, 'add_exchange_rate', $rate, null, $rate->toArray());

            return $rate;
        });
    }

    /**
     * Returns all active currencies for a company for use in dropdowns.
     * Includes functional currency.
     */
    public function activeForDropdown(int $companyId): EloquentCollection
    {
        $activeCurrencyIds = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('currency_id');

        return Currency::withoutGlobalScopes()
            ->whereNull('company_id')
            ->whereIn('id', $activeCurrencyIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'symbol']);
    }

    // ── Exchange Rates — Excel Import ─────────────────────────────
    //
    // Same two-step pattern as LeasingService::parseChequesExcel() /
    // saveCheques(): parse first, let the user review every row's
    // status in the browser, then commit only the rows they confirm.
    //
    // Columns are matched by HEADER NAME, not fixed position — this
    // was originally position-based (A=code, C=rate, D=date, mirroring
    // ExchangeRateExport's 5-column layout exactly) but that silently
    // broke on a simpler 3-column file (Currency / Rate / Effective
    // Date), which is what most people reach for when asked to
    // "just export the rate for each currency." Name-matching makes
    // both layouts work from the same parser:
    //   - The plain 3-column template (see downloadRateTemplate())
    //   - The full 5-column Export Excel file, unchanged, since its
    //     "Currency Name" and "Entered By" columns are simply not
    //     among the names this method looks for and are ignored
    // The Rate header is matched with str_starts_with() rather than
    // an exact match because ExchangeRateExport's heading is dynamic
    // — "Rate (1 Foreign = ? EGP)" — not the literal word "Rate".

    /**
     * Parses an uploaded Excel/CSV file of exchange rates and
     * classifies every row as valid / duplicate / skipped / error —
     * does NOT write to the database. The user reviews this list and
     * confirms before importRates() is called.
     *
     * @throws \DomainException if the header row is missing one of
     *         the three required columns (Currency, Rate, Effective
     *         Date) — caught by the controller and shown as a single
     *         clear message rather than 0 silently-wrong rows.
     */
    public function parseRatesExcel(\Illuminate\Http\UploadedFile $file, int $companyId): array
    {
        $rows = \Maatwebsite\Excel\Facades\Excel::toArray([], $file)[0] ?? [];

        if (empty($rows)) {
            throw new \DomainException('The file appears to be empty.');
        }

        $header  = array_shift($rows); // remaining $rows are data rows only
        $columns = $this->matchRateImportColumns($header);

        $missing = array_keys(array_filter($columns, fn ($idx) => $idx === null));
        if (! empty($missing)) {
            throw new \DomainException(
                'Could not find these required column(s) in the header row: ' . implode(', ', $missing) . '. '
                . 'Download the template for the exact format expected.'
            );
        }

        $currenciesByCode = Currency::withoutGlobalScopes()
            ->whereNull('company_id')
            ->get(['id', 'code'])
            ->keyBy(fn ($c) => strtoupper($c->code));

        $functionalCurrencyId = $this->functionalCurrencyId($companyId);
        $activeCurrencyIds    = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('currency_id')
            ->all();

        $parsed = [];
        foreach ($rows as $row) {
            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) continue; // blank row

            $code     = strtoupper(trim((string) ($row[$columns['Currency']] ?? '')));
            $rateRaw  = $row[$columns['Rate']] ?? null;
            $rateDate = $this->parseRateExcelDate($row[$columns['Effective Date']] ?? null);
            $currency = $currenciesByCode->get($code);

            $entry = [
                'currency_code' => $code,
                'currency_id'   => $currency?->id,
                'rate'          => $rateRaw !== null && $rateRaw !== '' ? (string) $rateRaw : null,
                'rate_date'     => $rateDate,
                'status'        => 'valid',
                'message'       => null,
            ];

            if ($code === '' || $entry['rate'] === null || $rateDate === null) {
                $entry['status']  = 'error';
                $entry['message'] = 'Missing or unreadable currency code, rate, or date.';
            } elseif (! $currency) {
                $entry['status']  = 'error';
                $entry['message'] = "Currency code '{$code}' is not recognized.";
            } elseif ($currency->id === $functionalCurrencyId) {
                $entry['status']  = 'skipped';
                $entry['message'] = 'Functional currency — rate is always 1.000000, row will not be imported.';
            } elseif (! in_array($currency->id, $activeCurrencyIds, true)) {
                $entry['status']  = 'error';
                $entry['message'] = 'Currency is not activated for this company yet.';
            } elseif (ExchangeRate::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('currency_id', $currency->id)
                ->where('rate_date', $rateDate)
                ->exists()
            ) {
                $entry['status']  = 'duplicate';
                $entry['message'] = 'A rate for this currency on this date already exists — will be skipped.';
            }

            $parsed[] = $entry;
        }

        return $parsed;
    }

    /**
     * Commits the rows the user confirmed after reviewing
     * parseRatesExcel()'s output. Re-checks the functional-currency
     * and duplicate guards at commit time (not just trusting the
     * preview) in case time has passed or another rate was added in
     * between — same defensive re-check addRate() already does for
     * the single-row flow. Rows failing either guard are silently
     * counted as skipped rather than aborting the whole batch, since
     * the user has already seen and accepted that some preview rows
     * won't be imported.
     */
    public function importRates(int $companyId, array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($companyId, $rows, $actor, &$created, &$skipped) {
            foreach ($rows as $row) {
                $currencyId = $row['currency_id'] ?? null;
                $rate       = $row['rate']        ?? null;
                $rateDate   = $row['rate_date']   ?? null;

                if (! $currencyId || $rate === null || $rate === '' || ! $rateDate) {
                    $skipped++;
                    continue;
                }

                $isFunctional = CompanyActiveCurrency::where('company_id', $companyId)
                    ->where('currency_id', $currencyId)
                    ->where('is_functional', true)
                    ->exists();

                $duplicate = ExchangeRate::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('currency_id', $currencyId)
                    ->where('rate_date', $rateDate)
                    ->exists();

                if ($isFunctional || $duplicate) {
                    $skipped++;
                    continue;
                }

                $rateModel = ExchangeRate::create([
                    'company_id'  => $companyId,
                    'currency_id' => $currencyId,
                    'rate'        => bcadd((string) $rate, '0', 6),
                    'rate_date'   => $rateDate,
                    'source'      => ExchangeRate::SOURCE_MANUAL,
                    'created_by'  => $actor->id,
                    'created_at'  => now(),
                ]);

                AuditLog::record($actor, 'import_exchange_rate', $rateModel, null, $rateModel->toArray());

                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Scans the header row and returns the column index of each
     * required field, matched by name rather than fixed position.
     * Returns null for any field whose column wasn't found — the
     * caller (parseRatesExcel) turns that into a clear error instead
     * of silently reading the wrong column.
     *
     * Accepted header text (case-insensitive, whitespace-trimmed):
     *   Currency       → "Currency" or anything containing "currency code"
     *   Rate           → anything STARTING WITH "rate" (covers both the
     *                     plain "Rate" header and ExchangeRateExport's
     *                     dynamic "Rate (1 Foreign = ? EGP)" heading)
     *   Effective Date → "Date" or anything containing "effective date"
     * Any other column (e.g. "Currency Name", "Entered By") is simply
     * not matched by any of the above and is ignored.
     */
    private function matchRateImportColumns(array $header): array
    {
        $columns = ['Currency' => null, 'Rate' => null, 'Effective Date' => null];

        foreach ($header as $idx => $cell) {
            $h = strtolower(trim((string) $cell));
            if ($h === '') continue;

            if ($columns['Currency'] === null && ($h === 'currency' || str_contains($h, 'currency code'))) {
                $columns['Currency'] = $idx;
            } elseif ($columns['Rate'] === null && str_starts_with($h, 'rate')) {
                $columns['Rate'] = $idx;
            } elseif ($columns['Effective Date'] === null && ($h === 'date' || str_contains($h, 'effective date'))) {
                $columns['Effective Date'] = $idx;
            }
        }

        return $columns;
    }

    /**
     * Same numeric-serial / text-date handling as
     * LeasingService::parseChequesExcel(), plus an explicit d/m/Y
     * branch — ExchangeRateExport writes dates as literal 'd/m/Y'
     * text (see its collection() method), and strtotime() alone
     * would misread e.g. "05/07/2026" as May 7th under a US locale.
     * Checking d/m/Y first (before the generic strtotime fallback)
     * is what makes export → edit → re-import round-trip correctly.
     */
    private function parseRateExcelDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $value = trim((string) $value);

        try {
            return \Carbon\Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
        } catch (\Throwable $e) {
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : null;
        }
    }

    // ── Shared dropdown helpers (consolidated July 2026 audit) ────────
    //
    // The pair below replaces an identical private-method pattern that
    // had been copy-pasted into 10 separate controllers (every
    // Financial Institutions facility controller, Purchase Order, and
    // both Projects controllers) — each with its own "BUG FIX" comment
    // explaining the same withoutGlobalScopes() requirement. One
    // shared, tested implementation here instead of ten copies that
    // could each drift independently.
    //
    // Unlike activeForDropdown() above (system currencies only, plain
    // code order — used by the Currency management page itself), this
    // variant is for transaction forms: it includes company-specific
    // currency overrides too, and puts the company's own functional
    // currency first in the list so it's the obvious default.

    public function functionalCurrencyId(int $companyId): ?int
    {
        return CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');
    }

    public function activeCurrenciesOrdered(int $companyId): EloquentCollection
    {
        $activeCurrencyIds = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('currency_id');

        $functionalCurrencyId = $this->functionalCurrencyId($companyId) ?? 0;

        // System currencies have company_id = NULL. withoutGlobalScopes()
        // is kept here even though CompanyScope itself was fixed (July
        // 2026 audit) to allow NULL company_id through globally — belt
        // and suspenders, and it makes the intent explicit at the call site.
        return Currency::withoutGlobalScopes()
            ->whereIn('id', $activeCurrencyIds)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$functionalCurrencyId])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }
}