<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RecordLeasePaymentRequest
//
//  Validates LeasingController::recordPayment() — marking one lease
//  schedule line as actually paid, which posts a journal entry
//  (Dr 556 interest + Dr 213 principal / Cr bank).
//
//  AUDIT M5 FIX (Sept 2026): the payment date used to be taken from
//  the request with no check at all — any text, a future date, or a
//  date years before the lease existed went straight into the GL.
//  Now: optional (defaults to today), must be a real calendar date,
//  not in the future. LeaseAmortizationService::recordPayment()
//  additionally refuses dates before the lease start date.
//
//  "Today" is Cairo's date, not the server's UTC date (audit M40).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RecordLeasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid_on_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . now('Africa/Cairo')->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'paid_on_date.before_or_equal' => 'The payment date cannot be in the future.',
        ];
    }
}
