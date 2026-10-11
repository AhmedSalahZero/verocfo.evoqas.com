<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  CurrencyController
//
//  HTTP layer for the Currencies settings page.
//  One page with two tabs: Currencies and Exchange Rates.
//  Zero business logic — all delegated to CurrencyService.
//
//  Routes:
//    GET    /settings/currencies                    → index()
//    POST   /settings/currencies/{id}/toggle        → toggle()
//    GET    /settings/currencies/{id}/rates         → rates() [JSON]
//    POST   /settings/currencies/rates              → addRate()
//    POST   /settings/currencies/rates/import-preview → importRatesPreview() [JSON]
//    POST   /settings/currencies/rates/import       → importRates()
//    GET    /settings/currencies/rates/template     → downloadRateTemplate()
//
//  @layer    Controller
//  @depends  App\Services\CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Exports\ExchangeRateExport;
use App\Exports\ExchangeRateImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ImportExchangeRatesRequest;
use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class CurrencyController extends Controller
{
    public function __construct(private CurrencyService $service) {}

    // ── Index — main currencies page ──────────────────────────────

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $data      = $this->service->listWithStatus($companyId);

        return Inertia::render('Settings/Currencies/Index', [
            'functional'          => $data['functional'],
            'currencies'          => $data['currencies'],
            'latest_per_currency' => $this->service->latestPerCurrency($companyId),
        ]);
    }

    // ── Currency rate history — JSON for dynamic filtering ────────

    public function history(Request $request)
    {
        $validated = $request->validate([
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $rates = $this->service->historyForCurrency(
            $this->companyId($request),
            $validated['currency_id'],
            $validated['date_from'] ?? null,
            $validated['date_to']   ?? null
        );

        return response()->json($rates);
    }

    // ── Toggle currency activation ────────────────────────────────

    public function toggle(Request $request, Currency $currency)
    {
        try {
            $this->service->toggleActivation(
                $this->companyId($request),
                $currency->id,
                $request->user()
            );
            return back()->with('success', "Currency [{$currency->code}] status updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Exchange rate history for a currency (JSON) ───────────────

    public function rates(Request $request, Currency $currency)
    {
        $rates = $this->service->ratesForCurrency(
            $this->companyId($request),
            $currency->id
        );

        return response()->json($rates);
    }

    // ── Add exchange rate ─────────────────────────────────────────

    public function addRate(Request $request)
    {
        $validated = $request->validate([
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'rate'        => ['required', 'numeric', 'min:0.000001'],
            'rate_date'   => ['required', 'date'],
        ]);

        try {
            $rate = $this->service->addRate(
                $this->companyId($request),
                $validated,
                $request->user()
            );
            return back()->with('success', "Rate [{$rate->rate}] for {$rate->rate_date->format('d/m/Y')} saved.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Exchange rates — Excel import (parse then confirm) ─────────

    public function importRatesPreview(ImportExchangeRatesRequest $request)
    {
        try {
            $rows = $this->service->parseRatesExcel(
                $request->file('file'),
                $this->companyId($request)
            );

            return response()->json(['rows' => $rows]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function importRates(Request $request)
    {
        $validated = $request->validate([
            'rows'              => ['required', 'array', 'min:1'],
            'rows.*.currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'rows.*.rate'         => ['required', 'numeric', 'min:0.000001'],
            'rows.*.rate_date'    => ['required', 'date'],
        ]);

        $result = $this->service->importRates(
            $this->companyId($request),
            $validated['rows'],
            $request->user()
        );

        $message = "{$result['created']} exchange rate(s) imported.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} row(s) skipped (duplicates or no longer valid).";
        }

        return back()->with('success', $message);
    }

    // ── Downloadable import template ────────────────────────────────

    public function downloadRateTemplate(Request $request)
    {
        $companyId      = $this->companyId($request);
        $data           = $this->service->listWithStatus($companyId);
        $functionalCode = $data['functional']?->code ?? 'EGP';

        return Excel::download(
            new ExchangeRateImportTemplateExport($functionalCode),
            'exchange-rate-import-template.xlsx'
        );
    }

    // ── Export exchange rates to Excel ────────────────────────────

    public function exportRates(Request $request)
    {
        $companyId      = $this->companyId($request);
        $data           = $this->service->listWithStatus($companyId);
        $rates          = $this->service->allRatesForExport($companyId);
        $functionalCode = $data['functional']?->code ?? 'EGP';
        $filename       = 'exchange-rates-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new ExchangeRateExport($rates, $functionalCode), $filename);
    }
}