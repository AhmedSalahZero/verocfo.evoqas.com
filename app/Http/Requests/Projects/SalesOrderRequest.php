<?php

namespace App\Http\Requests\Projects;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderRequest
//
//  Validates create and update requests for Sales Orders, including
//  the lines repeater. Extracted from SalesOrderController::rules()
//  — was previously validated inline in the controller (Rule 3.2
//  exception), moved here to match the house style already used by
//  PartnerRequest, ProductRequest, etc. No rule changed — this is a
//  straight move, not a re-design.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;

class SalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id'      => ['nullable', 'exists:projects,id'],
            'partner_id'      => ['required', 'exists:partners,id'],
            'subject'         => ['nullable', 'string', 'max:300'],
            'description'     => ['nullable', 'string'],
            'currency_id'     => ['required', 'exists:currencies,id'],
            'exchange_rate'   => ['required', 'numeric', 'min:0'],
            'issuance_date'   => ['required', 'date'],
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'after:start_date'],
            'payment_term_id' => ['nullable', new ExistsForCompany('payment_terms', true)], // Audit M1: own company or system only
            'internal_notes'  => ['nullable', 'string'],

            // ── Lines repeater ────────────────────────────────────
            'lines'                       => ['array'],
            'lines.*.product_id'          => ['nullable', 'exists:products,id'],
            'lines.*.description'         => ['required', 'string', 'max:500'],
            'lines.*.quantity'            => ['required', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'              => ['nullable', 'exists:units_of_measure,id'],
            'lines.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.revenue_account_id'  => ['nullable', 'exists:chart_of_accounts,id'],
            'lines.*.cost_center_id'      => ['nullable', 'exists:cost_centers,id'],
        ];
    }
}
