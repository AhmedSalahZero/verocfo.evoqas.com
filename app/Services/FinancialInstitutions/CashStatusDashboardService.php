<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CashStatusDashboardService
//
//  Financial Institutions > Dashboard > Cash Status — the VeroCFO
//  equivalent of CashVero's CashDashboardService::build(). Same
//  structure Mahmoud asked to match: per-currency Cash Position
//  (Cash & Banks / Time Deposit / Certificate of Deposit / Total),
//  a combined "Total Cash Facilities" card, one section per OD
//  facility type the company actually has (Limit/Outstanding/Room/
//  Interest, "Available Room by Bank" donut data, and the bank
//  picker for the Bank Movement chart), plus Medium/Long Term Loans
//  and Leasing Contracts tables.
//
//  Ported adaptations from CashVero (documented, not silently
//  dropped — same convention as the rest of this migration):
//   - No Certificate of Deposit concept exists in VeroCFO at all
//     (only Time Deposit). The KPI card stays in the layout for
//     structural parity but always reads 0 — flagged here and in
//     the Vue page rather than silently invented.
//   - CashVero reads real bank statement rows via a dedicated
//     per-account ledger; VeroCFO already has that exact engine —
//     LedgerStatementService — reused directly here for both the
//     Cash & Banks ending balance and every OD facility's Outstanding
//     balance, instead of rebuilding it.
//   - "Interest" per OD section = SUM of posted FacilityLiabilityCharge
//     rows with charge_type = 'interest', charge_date in the current
//     calendar year (year-to-date cost of carrying that facility) —
//     CashVero's own figure wasn't inspectable from the ported Vue
//     alone, so this is a reasonable, documented choice, not a guess
//     presented as fact.
//   - CashVero's "Bank Movement" chart lets the user pick a specific
//     bank account NUMBER within a bank. VeroCFO's OD facility IS the
//     account (one coa_account_id per facility) — so here the picker
//     is Bank → Facility (a company can have more than one facility
//     of the same type at the same bank), not Bank → Account Number.
//
//  AUDIT H15 (Sept 2026) — "EGP figures labelled as USD". The ledger
//  is kept in EGP, so reading a USD bank account's ledger balance gave
//  an EGP number that was then shown under the USD tab. Safes (which
//  have no bank-account currency) were put under whichever currency
//  came first alphabetically — e.g. AED.
//  Now:
//   • Every bank account, safe and facility shows its NATIVE balance
//     (from the foreign amount each journal line now carries — see
//     ForeignCurrencyLedgerService) under its own currency, PLUS its
//     EGP equivalent (the book value in the ledger).
//   • A safe sits under its own Chart of Accounts currency when one is
//     set, otherwise under the company's MAIN currency — never under
//     "the first currency in the list".
//   • When an account's foreign balance cannot be fully known (lines
//     posted with no exchange rate on file, or one account shared by
//     two currencies) the row is marked native_complete = false and
//     the page says so, instead of showing a number that is wrong.
//   • The Bank Movement chart of a foreign-currency facility is drawn
//     in that facility's own currency.
//   • Loan / lease totals use bcmath (Rule 4).
//
//  @layer    Service
//  @depends  LedgerStatementService, ForeignCurrencyLedgerService,
//            FxService, CreditFacility, BankAccount, ChartOfAccount,
//            FacilityLiabilityCharge, MtlScheduleLine, LeasingContract,
//            LeaseCheque
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\LeaseCheque;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Services\Accounting\ForeignCurrencyLedgerService;
use App\Services\Accounting\FxService;
use App\Services\Accounting\LedgerStatementService;
use Illuminate\Support\Facades\DB;

class CashStatusDashboardService
{
    public const OD_TYPES = [
        'fully_secured'    => 'Fully Secured Overdraft',
        'clean_od'         => 'Clean Overdraft',
        'commercial_paper' => 'OD Against Commercial Paper',
        'contract_so'      => 'OD Against Contract / SO',
    ];

    public function __construct(
        private LedgerStatementService $ledger,
        private ForeignCurrencyLedgerService $foreignLedger,
        private FxService $fx,
    ) {}

    /** The company's main (functional) currency code — never "first in the list". */
    public function mainCurrency(int $companyId): string
    {
        $id = $this->fx->functionalCurrencyId($companyId);
        $code = $id ? DB::table('currencies')->where('id', $id)->value('code') : null;

        return strtoupper((string) ($code ?: 'EGP'));
    }

    public function currencies(int $companyId): array
    {
        $rows = DB::table('company_active_currencies')
            ->join('currencies', 'currencies.id', '=', 'company_active_currencies.currency_id')
            ->where('company_active_currencies.company_id', $companyId)
            ->orderBy('currencies.code')
            ->pluck('currencies.code')
            ->all();

        return $rows ?: ['EGP'];
    }

    public function build(int $companyId, string $date): array
    {
        $currencies = $this->currencies($companyId);

        $cashAndBanks   = $this->cashAndBanksPosition($companyId, $date, $currencies);
        $timeDeposits   = $this->timeDepositPosition($companyId, $currencies, $date);

        $reports = [
            'cash_and_banks'          => $cashAndBanks['totals'],
            'time_deposits'           => $timeDeposits['totals'],
            'certificate_of_deposits' => array_fill_keys($currencies, '0.00'),
            'total'                   => [],
        ];
        // H15: the EGP equivalent of each native figure, shown beside it.
        $reportsFunctional = [
            'cash_and_banks' => $cashAndBanks['functional'],
            'time_deposits'  => $timeDeposits['functional'],
            'total'          => [],
        ];
        foreach ($currencies as $c) {
            $reports['total'][$c] = (string) bcadd(
                $reports['cash_and_banks'][$c] ?? '0.00',
                $reports['time_deposits'][$c] ?? '0.00',
                2
            );
            $reportsFunctional['total'][$c] = bcadd(
                $reportsFunctional['cash_and_banks'][$c] ?? '0.00',
                $reportsFunctional['time_deposits'][$c] ?? '0.00',
                2
            );
        }

        $details = [];
        foreach ($currencies as $c) {
            $details[$c] = [
                'cash_and_banks'          => $cashAndBanks['details'][$c] ?? [],
                'time_of_deposits'        => $timeDeposits['details'][$c] ?? [],
                'certificate_of_deposits' => [],
            ];
        }

        $odSections = [];
        $totalCard  = array_fill_keys($currencies, ['limit' => '0.00', 'outstanding' => '0.00', 'room' => '0.00', 'interest_amount' => '0.00', 'outstanding_functional' => '0.00']);

        foreach (self::OD_TYPES as $type => $label) {
            $section = $this->odSection($companyId, $type, $currencies, $date);
            $odSections[$type] = $section;

            foreach ($currencies as $c) {
                $totalCard[$c]['limit']           = (string) bcadd($totalCard[$c]['limit'], $section['cardData'][$c]['limit'] ?? '0.00', 2);
                $totalCard[$c]['outstanding']      = (string) bcadd($totalCard[$c]['outstanding'], $section['cardData'][$c]['outstanding'] ?? '0.00', 2);
                $totalCard[$c]['room']             = (string) bcadd($totalCard[$c]['room'], $section['cardData'][$c]['room'] ?? '0.00', 2);
                $totalCard[$c]['interest_amount']  = (string) bcadd($totalCard[$c]['interest_amount'], $section['cardData'][$c]['interest_amount'] ?? '0.00', 2);
                $totalCard[$c]['outstanding_functional'] = bcadd($totalCard[$c]['outstanding_functional'], $section['cardData'][$c]['outstanding_functional'] ?? '0.00', 2);
            }
        }

        return [
            'selectedCurrencies' => $currencies,
            'mainCurrency'       => $this->mainCurrency($companyId),
            'reports'            => $reports,
            'reportsFunctional'  => $reportsFunctional,
            // Per currency: how many accounts' foreign balances are not
            // fully known (see class header) — the page shows a warning.
            'incompleteNative'   => $this->mergeCounts($cashAndBanks['incomplete'], $timeDeposits['incomplete']),
            'details'            => $details,
            'totalCard'          => $totalCard,
            'odSections'         => $odSections,
            'mediumTermLoans'    => $this->mediumTermLoans($companyId, $currencies),
            'leasingContracts'   => $this->leasingContracts($companyId, $currencies),
        ];
    }

    /**
     * Opening cash position (Cash & Banks + Time Deposit) per currency,
     * as of a given date — reused by CashFlowProjectionService as the
     * starting point for Accumulated Net Cash, instead of duplicating
     * this calculation a second time.
     *
     * H15: in each currency's OWN units (a USD account adds USD, not
     * its EGP book value) — the projection it feeds is per currency.
     */
    public function openingCashPosition(int $companyId, string $date, array $currencies): array
    {
        $cashAndBanks = $this->cashAndBanksPosition($companyId, $date, $currencies);
        $timeDeposits = $this->timeDepositPosition($companyId, $currencies, $date);

        $out = array_fill_keys($currencies, '0.00');
        foreach ($currencies as $c) {
            $out[$c] = (string) bcadd($cashAndBanks['totals'][$c] ?? '0.00', $timeDeposits['totals'][$c] ?? '0.00', 2);
        }
        return $out;
    }

    // ── Cash & Banks (current accounts + safes) ─────────────────────

    private function cashAndBanksPosition(int $companyId, string $date, array $currencies): array
    {
        $totals     = array_fill_keys($currencies, '0.00');
        $functional = array_fill_keys($currencies, '0.00');
        $incomplete = array_fill_keys($currencies, 0);
        $details    = array_fill_keys($currencies, []);
        $mainCurrency = $this->mainCurrency($companyId);

        $accounts = BankAccount::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('account_type', 'current')
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->with(['bank:id,name_en', 'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code')])
            ->get();

        foreach ($accounts as $account) {
            $code = strtoupper((string) $account->currency?->code);
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $position = $this->accountPosition($companyId, (int) $account->coa_account_id, $code, $mainCurrency, $date);

            $totals[$code]     = bcadd($totals[$code], $position['native'] ?? '0', 2);
            $functional[$code] = bcadd($functional[$code], $position['functional'], 2);
            if (! $position['complete']) $incomplete[$code]++;

            $details[$code][] = [
                'source'                     => 'Bank Account',
                'financial_institution_name' => $account->bank?->name_en,
                'account_number'             => $account->account_number,
                'amount'                     => $position['native'],
                'amount_functional'          => $position['functional'],
                'native_complete'            => $position['complete'],
            ];
        }

        $safes = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', 'like', '131%')->where('is_postable', true)
            ->get(['id', 'code', 'name_en', 'currency_id']);

        // H15: a safe belongs to its own COA currency when one is set,
        // otherwise to the company's MAIN currency — never simply to
        // the first currency in the (alphabetical) list.
        $foreignMap = $this->foreignLedger->accountCurrencyMap($companyId, $safes->pluck('id')->all());
        $codes = DB::table('currencies')->pluck('code', 'id');

        foreach ($safes as $safe) {
            $code = isset($foreignMap[$safe->id]) ? strtoupper((string) ($codes[$foreignMap[$safe->id]] ?? '')) : $mainCurrency;
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $position = $this->accountPosition($companyId, (int) $safe->id, $code, $mainCurrency, $date);
            if (bccomp($position['functional'], '0', 2) === 0 && bccomp($position['native'] ?? '0', '0', 2) === 0) continue;

            $totals[$code]     = bcadd($totals[$code], $position['native'] ?? '0', 2);
            $functional[$code] = bcadd($functional[$code], $position['functional'], 2);
            if (! $position['complete']) $incomplete[$code]++;

            $details[$code][] = [
                'source'                     => 'Safe',
                'financial_institution_name' => $safe->name_en,
                'account_number'             => $safe->code,
                'amount'                     => $position['native'],
                'amount_functional'          => $position['functional'],
                'native_complete'            => $position['complete'],
            ];
        }

        // Largest balance first, per CashVero's own explicit product
        // decision (see its Vue page comment) — kept the same here.
        foreach ($details as $code => $rows) {
            usort($rows, fn ($a, $b) => bccomp($b['amount'] ?? '0', $a['amount'] ?? '0', 2));
            $details[$code] = $rows;
        }

        return ['totals' => $totals, 'functional' => $functional, 'incomplete' => $incomplete, 'details' => $details];
    }

    /**
     * H15 — one account's balance as of a date, in its OWN currency
     * ($code) plus its EGP book value. For a main-currency account the
     * two are the same. For a foreign one the native figure comes from
     * the foreign amounts on its journal lines; 'complete' is false
     * when some of those are unknown (no rate on file) or the account
     * is shared by two currencies — then 'native' is what is known.
     */
    private function accountPosition(int $companyId, int $coaId, string $code, string $mainCurrency, string $date, string $sign = LedgerStatementService::SIGN_ASSET): array
    {
        $functional = $this->ledgerEndingBalance($companyId, $coaId, $date, $sign);

        if ($code === $mainCurrency) {
            return ['native' => $functional, 'functional' => $functional, 'complete' => true];
        }

        $balance = $this->foreignLedger->balance($companyId, $coaId, $date);
        if ($balance === null) {
            // Not a single-currency account (e.g. shared by two currencies).
            return ['native' => null, 'functional' => $functional, 'complete' => false];
        }

        $native = $sign === LedgerStatementService::SIGN_LIABILITY
            ? bcmul($balance['native'], '-1', 2)
            : $balance['native'];

        return ['native' => $native, 'functional' => $functional, 'complete' => $balance['unknown_lines'] === 0];
    }

    private function mergeCounts(array ...$sets): array
    {
        $out = [];
        foreach ($sets as $set) {
            foreach ($set as $k => $v) {
                $out[$k] = ($out[$k] ?? 0) + $v;
            }
        }
        return $out;
    }

    private function timeDepositPosition(int $companyId, array $currencies, ?string $date = null): array
    {
        $totals     = array_fill_keys($currencies, '0.00');
        $functional = array_fill_keys($currencies, '0.00');
        $incomplete = array_fill_keys($currencies, 0);
        $details    = array_fill_keys($currencies, []);
        $mainCurrency = $this->mainCurrency($companyId);
        $date = $date ?: now()->toDateString();

        $tds = BankAccount::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('account_type', 'time_deposit')
            ->where('is_active', true)
            ->with(['bank:id,name_en', 'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code')])
            ->get();

        foreach ($tds as $td) {
            $code = strtoupper((string) $td->currency?->code);
            if (! $code || ! in_array($code, $currencies, true)) continue;

            // td_amount is the principal in the deposit's OWN currency.
            // Its EGP equivalent is the book value in the ledger when the
            // deposit has its own account; otherwise principal × rate.
            $native = (string) $td->td_amount;
            $equivalent = null;
            if ($code === $mainCurrency) {
                $equivalent = $native;
            } elseif ($td->coa_account_id && ($pos = $this->foreignLedger->balance($companyId, (int) $td->coa_account_id, $date))) {
                $equivalent = $this->ledgerEndingBalance($companyId, (int) $td->coa_account_id, $date);
            } else {
                $rate = \App\Models\ExchangeRate::rateFor($companyId, (int) $td->currency_id, $date);
                $equivalent = $rate ? $this->fx->toFunctional($native, $rate) : null;
            }

            $totals[$code] = bcadd($totals[$code], $native, 2);
            if ($equivalent === null) {
                $incomplete[$code]++;
            } else {
                $functional[$code] = bcadd($functional[$code], $equivalent, 2);
            }
            $details[$code][] = [
                'financial_institution_name' => $td->bank?->name_en,
                'amount'                     => $native,
                'amount_functional'          => $equivalent,
            ];
        }

        return ['totals' => $totals, 'functional' => $functional, 'incomplete' => $incomplete, 'details' => $details];
    }

    // ── OD facility sections ─────────────────────────────────────

    private function odSection(int $companyId, string $facilityType, array $currencies, ?string $asOfDate = null): array
    {
        $mainCurrency = $this->mainCurrency($companyId);
        $facilities = CreditFacility::where('company_id', $companyId)
            ->where('facility_type', $facilityType)
            ->where('status', 'active')
            ->whereNotNull('coa_account_id')
            ->with(['bank:id,name_en', 'currency:id,code'])
            ->get();

        $cardData = array_fill_keys($currencies, ['limit' => '0.00', 'outstanding' => '0.00', 'room' => '0.00', 'interest_amount' => '0.00', 'outstanding_functional' => '0.00', 'incomplete' => 0]);
        $roomRows = array_fill_keys($currencies, []);
        $banksByCurrency = array_fill_keys($currencies, []);
        $has = array_fill_keys($currencies, false);

        $yearEnd   = $asOfDate ?: now()->toDateString();
        $yearStart = substr($yearEnd, 0, 4).'-01-01';

        foreach ($facilities as $facility) {
            $code = strtoupper((string) $facility->currency?->code);
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $has[$code] = true;

            // H15: outstanding in the facility's OWN currency (the limit
            // is in that currency too), plus its EGP book value.
            $position    = $this->accountPosition($companyId, (int) $facility->coa_account_id, $code, $mainCurrency, $yearEnd, LedgerStatementService::SIGN_LIABILITY);
            $outstanding = $position['native'] ?? '0.00';
            $room        = bcsub((string) $facility->facility_limit, $outstanding, 2);
            if (! $position['complete']) $cardData[$code]['incomplete']++;
            $interest    = (string) FacilityLiabilityCharge::where('company_id', $companyId)
                ->where('credit_facility_id', $facility->id)
                ->interest()->posted()
                ->whereBetween('charge_date', [$yearStart, $yearEnd])
                ->sum('actual_amount');

            $cardData[$code]['limit']          = (string) bcadd($cardData[$code]['limit'], (string) $facility->facility_limit, 2);
            $cardData[$code]['outstanding']    = (string) bcadd($cardData[$code]['outstanding'], $outstanding, 2);
            $cardData[$code]['room']           = (string) bcadd($cardData[$code]['room'], $room, 2);
            $cardData[$code]['interest_amount']= (string) bcadd($cardData[$code]['interest_amount'], $interest, 2);
            $cardData[$code]['outstanding_functional'] = bcadd($cardData[$code]['outstanding_functional'], $position['functional'], 2);

            $roomRows[$code][] = [
                'item'            => $facility->bank?->name_en,
                'facility_id'     => $facility->id,
                'facility_reference' => $facility->facility_reference,
                'limit'           => (string) $facility->facility_limit,
                'end_balance'     => $outstanding,
                'end_balance_functional' => $position['functional'],
                'native_complete' => $position['complete'],
                'available_room'  => $room,
                'interest'        => $interest,
            ];

            $bankId = $facility->bank_id;
            if ($bankId && ! collect($banksByCurrency[$code])->firstWhere('id', $bankId)) {
                $banksByCurrency[$code][] = ['id' => $bankId, 'name' => $facility->bank?->name_en];
            }
        }

        return [
            'label'     => self::OD_TYPES[$facilityType],
            'type'      => $facilityType,
            'cardData'  => $cardData,
            'roomRows'  => $roomRows,
            'banks'     => $banksByCurrency,
            'has'       => $has,
        ];
    }

    /** Facilities for a bank, within one OD type + currency — feeds the Bank Movement picker.
     *  Labeled by facility_reference (the bank's own reference number for
     *  that OD facility — the closest VeroCFO equivalent to a "bank
     *  account number" for these facility types), falling back to a
     *  linked bank account's account_number on the rare facility type
     *  that has one, and only then a bare '#id' as a last resort.
     *
     *  CORRECTION: an earlier version of this method tried
     *  bankAccount()->account_number FIRST. That was wrong — confirmed
     *  by inspecting CleanOverdraftService and the other OD-family
     *  services directly: bank_account_id is never set anywhere in
     *  their create/update flow. Every OD facility posts straight to
     *  its own coa_account_id; there is no linked "bank account number"
     *  entity behind it the way CashVero's original model has. If a
     *  facility still shows a bare '#id' here, that means its
     *  Facility Reference field is empty in Financial Institutions >
     *  that facility type > edit — filling it in fixes the label with
     *  no code change needed. */
    public function facilitiesForMovementPicker(int $companyId, string $facilityType, string $currencyCode, int $bankId): array
    {
        return CreditFacility::where('company_id', $companyId)
            ->where('facility_type', $facilityType)
            ->where('bank_id', $bankId)
            ->where('status', 'active')
            ->whereNotNull('coa_account_id')
            ->whereHas('currency', fn ($q) => $q->withoutGlobalScopes()->where('code', $currencyCode))
            ->with('bankAccount:id,account_number')
            ->get(['id', 'facility_reference', 'bank_account_id'])
            ->map(fn ($f) => [
                'value' => (string) $f->id,
                'label' => $f->facility_reference ?: ($f->bankAccount?->account_number ?: ('#'.$f->id)),
            ])
            ->values()->all();
    }

    /** Bank Movement chart rows for one facility — reuses LedgerStatementService directly. */
    public function movementChart(int $companyId, int $facilityId, ?string $startDate, ?string $endDate): array
    {
        $facility = CreditFacility::where('company_id', $companyId)->findOrFail($facilityId);
        if (! $facility->coa_account_id) return [];

        // H15: a foreign-currency facility is charted in its OWN
        // currency (the tab it sits under), not in EGP book values.
        if ($this->foreignLedger->balance($companyId, (int) $facility->coa_account_id, $endDate ?: now()->toDateString()) !== null) {
            return $this->nativeMovementChart($companyId, (int) $facility->coa_account_id, $startDate, $endDate);
        }

        $rows = $this->ledger->all($companyId, $facility->coa_account_id, $startDate, $endDate, LedgerStatementService::SIGN_LIABILITY);

        return $rows->map(fn ($r) => [
            'date'        => \Carbon\Carbon::parse($r->entry_date)->toDateString(),
            'debit'       => (string) $r->debit,
            'credit'      => (string) $r->credit,
            'end_balance' => (string) $r->end_balance,
        ])->values()->all();
    }

    /** Liability-signed running balance in the account's foreign currency. */
    private function nativeMovementChart(int $companyId, int $coaId, ?string $startDate, ?string $endDate): array
    {
        $startDate = $startDate ?: '2000-01-01';
        $endDate   = $endDate ?: now()->toDateString();

        $opening = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)->where('l.chart_of_account_id', $coaId)
            ->where('e.entry_date', '<', $startDate)
            ->sum('l.amount_currency');
        $running = bcmul((string) ($opening ?: '0'), '-1', 2);

        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)->where('l.chart_of_account_id', $coaId)
            ->whereBetween('e.entry_date', [$startDate, $endDate])
            ->orderBy('e.entry_date')->orderBy('l.id')
            ->get(['e.entry_date', 'l.amount_currency']);

        $out = [];
        foreach ($rows as $r) {
            $amount = (string) ($r->amount_currency ?? '0');
            $debit  = bccomp($amount, '0', 2) > 0 ? $amount : '0.00';
            $credit = bccomp($amount, '0', 2) < 0 ? bcmul($amount, '-1', 2) : '0.00';
            $running = bcsub(bcadd($running, $credit, 2), $debit, 2);
            $out[] = [
                'date'        => substr((string) $r->entry_date, 0, 10),
                'debit'       => $debit,
                'credit'      => $credit,
                'end_balance' => $running,
            ];
        }

        return $out;
    }

    /**
     * Flat list of past-due Medium Term Loan + Leasing installments as
     * of a date — feeds Cash Forecast's "Past Due Loans & Leasing
     * Installments" summary table.
     */
    public function pastDueInstallments(int $companyId, string $asOfDate): array
    {
        $asOf = \Carbon\Carbon::parse($asOfDate);

        // Audit H15: each row carries its own currency so the page can
        // show it under the right tab (it used to mix USD and EGP rows).
        $mtlRows = MtlScheduleLine::where('company_id', $companyId)
            ->where('due_date', '<', $asOf)
            ->with('creditFacility.currency:id,code')
            ->get()
            ->filter(fn ($l) => bccomp($l->remainingAmount(), '0', 2) > 0)
            ->map(fn ($l) => ['date' => $l->due_date->toDateString(), 'remaining' => $l->remainingAmount(), 'currency' => strtoupper((string) $l->creditFacility?->currency?->code)]);

        $leaseRows = LeaseCheque::where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('payment_date', '<', $asOf)
            ->with('leasingContract.currency:id,code')
            ->get()
            ->map(fn ($c) => ['date' => $c->payment_date->toDateString(), 'remaining' => (string) $c->cheque_amount, 'currency' => strtoupper((string) $c->leasingContract?->currency?->code)]);

        return $mtlRows->concat($leaseRows)->sortBy('date')->values()->all();
    }

    // ── Loans & Leasing ──────────────────────────────────────────

    private function mediumTermLoans(int $companyId, array $currencies): array
    {
        $out = array_fill_keys($currencies, []);

        $loans = CreditFacility::where('company_id', $companyId)
            ->where('facility_type', 'mtl')
            ->with(['bank:id,name_en', 'currency:id,code'])
            ->get();

        foreach ($loans as $loan) {
            $code = $loan->currency?->code;
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $lines = MtlScheduleLine::where('company_id', $companyId)
                ->where('credit_facility_id', $loan->id)
                ->orderBy('due_date')
                ->get();

            $paid        = $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->settledAmount(), 2), '0.00');
            $outstanding = $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->remainingAmount(), 2), '0.00');
            $next        = $lines->first(fn ($l) => $l->computedStatus() === 'upcoming');
            $pastDues    = $lines->filter(fn ($l) => in_array($l->computedStatus(), ['past_due', 'partially_paid']))
                ->map(fn ($l) => ['date' => $l->due_date->toDateString(), 'remaining' => $l->remainingAmount()])
                ->values();

            $out[$code][] = [
                'id'                 => $loan->id,
                'institution_name'   => $loan->bank?->name_en,
                'name'               => $loan->facility_reference ?: ('MTL #'.$loan->id),
                'limit_formatted'    => Money::display($loan->facility_limit),
                'paid_formatted'     => Money::display($paid),
                'outstanding_formatted' => Money::display($outstanding),
                'next_installment_date'   => $next?->due_date?->toDateString(),
                'next_installment_amount' => $next ? Money::display($next->schedule_payment) : null,
                'total_past_due_remaining_formatted' => Money::display($pastDues->reduce(fn ($c, $p) => bcadd($c, (string) $p['remaining'], 2), '0.00')),
                'past_dues'          => $pastDues->all(),
            ];
        }

        return $out;
    }

    private function leasingContracts(int $companyId, array $currencies): array
    {
        $out = array_fill_keys($currencies, []);

        $contracts = LeasingContract::where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['leasingCompany:id,name_en', 'currency:id,code'])
            ->get();

        foreach ($contracts as $contract) {
            $code = $contract->currency?->code;
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $cheques = LeaseCheque::where('company_id', $companyId)
                ->where('leasing_contract_id', $contract->id)
                ->orderBy('payment_date')
                ->get();

            $paid = $cheques->where('status', 'withdrawn')->reduce(fn ($c, $q) => bcadd($c, (string) $q->cheque_amount, 2), '0.00');
            $outstanding = (string) bcsub((string) $contract->total_financed_value ?: $contract->total_contract_value, $paid, 2);
            $next = $cheques->first(fn ($c) => $c->status === 'pending' && $c->payment_date->isFuture());
            $pastDues = $cheques->filter(fn ($c) => $c->status === 'pending' && $c->payment_date->isPast())
                ->map(fn ($c) => ['date' => $c->payment_date->toDateString(), 'remaining' => (string) $c->cheque_amount])
                ->values();

            $out[$code][] = [
                'id'                 => $contract->id,
                'institution_name'   => $contract->leasingCompany?->name_en,
                'name'               => $contract->contract_reference,
                'limit_formatted'    => Money::display($contract->total_financed_value ?: $contract->total_contract_value),
                'paid_formatted'     => Money::display($paid),
                'outstanding_formatted' => Money::display($outstanding),
                'next_installment_date'   => $next?->payment_date?->toDateString(),
                'next_installment_amount' => $next ? Money::display($next->cheque_amount) : null,
                'total_past_due_remaining_formatted' => Money::display($pastDues->reduce(fn ($c, $p) => bcadd($c, (string) $p['remaining'], 2), '0.00')),
                'past_dues'          => $pastDues->all(),
            ];
        }

        return $out;
    }

    // ── Shared helper ────────────────────────────────────────────

    private function ledgerEndingBalance(int $companyId, int $coaAccountId, string $asOfDate, string $sign = LedgerStatementService::SIGN_ASSET): string
    {
        $totals = $this->ledger->totals($companyId, $coaAccountId, null, $asOfDate, $sign);
        return (string) ($totals['ending_balance'] ?? '0.00');
    }
}
