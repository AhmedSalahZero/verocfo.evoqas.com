<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  ImportExchangeRatesRequest
//
//  Validates CurrencyController::importRatesPreview() — the Excel/CSV
//  upload used as an alternative to manually typing each exchange
//  rate one at a time on the Currencies settings page.
//
//  Same shape and limits as ImportLeaseChequesRequest (Leasing) —
//  kept as its own class rather than shared, since the two live in
//  different modules and may need to diverge later (e.g. a smaller
//  max size or an extra mime type for one but not the other).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ImportExchangeRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }
}
