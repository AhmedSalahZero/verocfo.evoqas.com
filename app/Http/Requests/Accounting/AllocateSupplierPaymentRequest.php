<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AllocateSupplierPaymentRequest
//
//  Validates SupplierPaymentController::allocate() — matching a
//  posted payment's cash against a specific open bill. Ceiling checks
//  (can't exceed unallocated_amount or the bill's amount_outstanding)
//  are enforced in SupplierPaymentService::allocate(), not here.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class AllocateSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_bill_id' => ['required', 'integer', 'exists:supplier_bills,id'],
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'application_date'     => ['nullable', 'date'], // Audit M-12
        ];
    }
}
