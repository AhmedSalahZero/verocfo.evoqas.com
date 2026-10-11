<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AllocatePaymentRequest
//
//  Validates CustomerPaymentController::allocate() — matching a
//  posted payment's cash against a specific open invoice. The
//  ceiling checks (can't exceed the payment's unallocated_amount or
//  the invoice's amount_outstanding) are enforced in
//  CustomerPaymentService::allocate(), not here — this Request only
//  validates shape.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class AllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_invoice_id' => ['required', 'integer', 'exists:customer_invoices,id'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'application_date'     => ['nullable', 'date'], // Audit M-12
        ];
    }
}
