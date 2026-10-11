<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerInvoiceRequest
//
//  Validates create/update for Customer Invoices. Deeper cross-
//  reference checks (SO active status, Rule 28.2 value ceiling) are
//  enforced in CustomerInvoiceService::post() instead — this Request
//  only validates shape and existence, same defense-in-depth split
//  used by SupplierBillRequest.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;

class CustomerInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_order_id'   => ['nullable', 'integer', 'exists:sales_orders,id'],
            'partner_id'       => ['required', 'integer', 'exists:partners,id'],
            'invoice_date'     => ['required', 'date'],
            'currency_id'      => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate'    => ['nullable', 'numeric', 'min:0.000001'],
            'payment_term_id'  => ['nullable', 'integer', new ExistsForCompany('payment_terms', true)], // Audit M1: own company or system only
            'internal_notes'   => ['nullable', 'string'],

            'lines'                              => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id'         => ['nullable', 'integer', 'exists:sales_order_lines,id', 'required_with:sales_order_id'],
            'lines.*.product_id'                  => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.description'                 => ['required', 'string', 'max:500'],
            'lines.*.quantity'                     => ['nullable', 'numeric', 'min:0.0001', 'required_without:sales_order_id'],
            'lines.*.uom_id'                       => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'lines.*.unit_price'                   => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate'                     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.revenue_account_id'           => ['nullable', 'integer', 'exists:chart_of_accounts,id', 'required_without:sales_order_id'],
            'lines.*.cost_center_id'               => ['nullable', 'integer', 'exists:cost_centers,id'],
            // Audit H18 — optional service period: revenue spread over it.
            'lines.*.service_period_start'         => ['nullable', 'date', 'required_with:lines.*.service_period_end'],
            'lines.*.service_period_end'           => ['nullable', 'date', 'after_or_equal:lines.*.service_period_start', 'required_with:lines.*.service_period_start'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sales_order_id' => 'Sales Order',
            'partner_id'     => 'Customer',
            'invoice_date'   => 'Invoice Date',
            'currency_id'    => 'Currency',
        ];
    }
}
