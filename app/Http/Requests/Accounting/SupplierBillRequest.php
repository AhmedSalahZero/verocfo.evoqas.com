<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierBillRequest
//
//  bill_type drives which lines are expected (Goods vs Expense — see
//  SupplierBillLine header for the exact shape rules), but this
//  Request only validates the SHAPE of what's present on each line —
//  the deeper "exactly one of the three" rule and every cross-
//  reference check (remaining GRN quantity, accrual remaining
//  amount, duplicate invoice) is enforced in SupplierBillService,
//  the same defense-in-depth split used by GoodsReceiptNoteRequest.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SupplierBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ModulePermission middleware gates the route itself
    }

    public function rules(): array
    {
        return [
            'bill_type'               => ['required', 'in:goods,expense,prepaid'],
            'partner_id'              => ['required', 'integer', 'exists:partners,id'],
            'purchase_order_id'       => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'supplier_invoice_date'   => ['nullable', 'date'],
            'bill_date'               => ['required', 'date'],
            'currency_id'             => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate'           => ['nullable', 'numeric', 'min:0.000001'],
            'payment_term_id'         => ['nullable', 'integer', new ExistsForCompany('payment_terms', true)], // Audit M1: own company or system only
            'internal_notes'          => ['nullable', 'string'],

            'lines'                                  => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_note_line_id'      => ['nullable', 'integer', 'exists:goods_receipt_note_lines,id'],
            'lines.*.chart_of_account_id'             => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.accrual_id'                      => ['nullable', 'integer', 'exists:accruals,id'],
            'lines.*.product_id'                      => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.description'                     => ['required', 'string', 'max:500'],
            'lines.*.quantity'                         => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                           => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'lines.*.unit_price'                       => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate'                         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.is_deductible'                    => ['nullable', 'boolean'],
            'lines.*.withholding_tax_rate'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.variance_inventory_pct'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Prepaid Expense Bill only — required exactly when the
            // bill itself is bill_type=prepaid (see migration 000192).
            'lines.*.amortization_months'              => ['required_if:bill_type,prepaid', 'nullable', 'integer', 'min:1', 'max:60'],
            'lines.*.cost_center_id'                   => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lines.*.project_id'                       => ['nullable', 'integer', 'exists:projects,id'],
            'lines.*.sales_order_id'                   => ['nullable', 'integer', 'exists:sales_orders,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'              => 'Supplier',
            'bill_date'                => 'Supplier Invoice Date',
            'supplier_invoice_date'    => 'Invoice Received On',
            'currency_id'              => 'Currency',
            'supplier_invoice_number'  => 'Supplier Invoice Number',
        ];
    }

    /**
     * Server-side safety net (July 2026): the form already hides an
     * already-picked GRN line from every other row's dropdown, but
     * that's a client-side convenience only — this is the real,
     * unavoidable check. Rejects the whole submission if the same
     * goods_receipt_note_line_id appears on more than one line.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = collect($this->input('lines', []))
                ->pluck('goods_receipt_note_line_id')
                ->filter();

            $duplicates = $lines->duplicates();

            if ($duplicates->isNotEmpty()) {
                $validator->errors()->add(
                    'lines',
                    'The same Goods Receipt Note line cannot be billed twice on one bill.'
                );
            }
        });
    }
}