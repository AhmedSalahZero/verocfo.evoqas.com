<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlementRequest
//
//  Validates InternalSettlementController::store()/update(). The
//  deeper cross-checks (allocations summing exactly to `amount`,
//  same-currency-only, correct partner) happen in
//  InternalSettlementService::postSettlementTransaction() — this
//  request only validates shape and presence.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class InternalSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'                                    => ['required', 'integer', 'exists:partners,id'],
            'currency_id'                                    => ['required', 'integer', 'exists:currencies,id'],
            'settlement_date'                                => ['required', 'date'],
            'amount'                                          => ['required', 'numeric', 'min:0.01'],
            'internal_notes'                                  => ['nullable', 'string'],

            'customer_allocations'                            => ['required', 'array', 'min:1'],
            'customer_allocations.*.customer_invoice_id'      => ['required', 'integer', 'exists:customer_invoices,id'],
            'customer_allocations.*.allocated_amount'         => ['required', 'numeric', 'min:0.01'],

            'supplier_allocations'                            => ['required', 'array', 'min:1'],
            'supplier_allocations.*.supplier_bill_id'         => ['required', 'integer', 'exists:supplier_bills,id'],
            'supplier_allocations.*.allocated_amount'         => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
