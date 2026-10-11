<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeaseFinalPurchaseRequest
//
//  Validates LeasingController::finalPurchase() — the end-of-lease
//  buy-out of an Operational (Treatment B) lease asset, which posts
//  Dr Fixed Asset / Cr ROU Asset.
//
//  AUDIT M5 FIX (Sept 2026): purchase_value used to be passed to the
//  ledger unchecked — blank (crashed with a server error), negative,
//  zero, text, or more than two decimals. Now it must be a positive
//  amount with at most 2 decimals. An optional purchase_date (defaults
//  to today) must be a real date, not in the future; the service
//  also refuses a date before the lease start date.
//
//  "Today" is Cairo's date, not the server's UTC date (audit M40).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LeaseFinalPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_value' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'purchase_date'  => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . now('Africa/Cairo')->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_value.required' => 'Enter the final purchase value.',
            'purchase_value.gt'       => 'The final purchase value must be greater than zero.',
            'purchase_value.decimal'  => 'The final purchase value can have at most 2 decimal places.',
            'purchase_date.before_or_equal' => 'The purchase date cannot be in the future.',
        ];
    }
}
