<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  PartnerRequest
//
//  Validates create and update requests for Partners.
//  Only name_en and at least one type are required.
//  All type-specific fields are nullable — the service and Vue
//  form enforce which fields are relevant per type.
//
//  Contacts repeater: each contact requires name_en at minimum.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;

class PartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Identity ──────────────────────────────────────────
            'name_en'              => ['required', 'string', 'max:255'],
            'name_ar'              => ['nullable', 'string', 'max:255'],
            'types'                => ['required', 'array', 'min:1'],
            'types.*'              => ['in:customer,supplier,employee,shareholder,sister_company,other'],

            // ── Contact ───────────────────────────────────────────
            'email'                => ['nullable', 'email', 'max:255'],
            'phone'                => ['nullable', 'string', 'max:30'],
            'address'              => ['nullable', 'string', 'max:500'],
            'country'              => ['nullable', 'string', 'max:50'],
            'tax_number'           => ['nullable', 'string', 'max:50'],
            'notes'                => ['nullable', 'string', 'max:1000'],

            // ── Customer fields ───────────────────────────────────
            'credit_limit'         => ['nullable', 'numeric', 'min:0'],
            'collection_term_id'   => ['nullable', new ExistsForCompany('payment_terms', true)], // Audit M1: own company or system only
            'sales_zone'           => ['nullable', 'string', 'max:100'],
            'sales_channel_id'     => ['nullable', new ExistsForCompany('sales_channels', true)], // Audit M1: own company or system only

            // ── Supplier fields ───────────────────────────────────
            'payment_term_id'      => ['nullable', new ExistsForCompany('payment_terms', true)], // Audit M1: own company or system only
            'expense_category'     => ['nullable', 'string', 'max:100'],

            // ── Customer + Supplier ───────────────────────────────
            'business_sector_id'   => ['nullable', 'exists:business_sectors,id'],

            // ── Employee fields ───────────────────────────────────
            'department'           => ['nullable', 'string', 'max:100'],
            'job_title'            => ['nullable', 'string', 'max:100'],
            'cost_center_id'       => ['nullable', 'exists:cost_centers,id'],
            'bank_name'            => ['nullable', 'string', 'max:100'],
            'bank_branch'          => ['nullable', 'string', 'max:100'],
            'bank_account_number'  => ['nullable', 'string', 'max:50'],
            'iban'                 => ['nullable', 'string', 'max:34'],
            'swift_code'           => ['nullable', 'string', 'max:11'],

            // ── Shareholder fields ────────────────────────────────
            'equity_pct'           => ['nullable', 'numeric', 'min:0', 'max:100'],

            // ── Sister Company fields ─────────────────────────────
            'sister_company_name'  => ['nullable', 'string', 'max:255'],

            // ── Landing Cost Supplier fields — see migration 000202 ──
            'is_landing_cost_supplier' => ['nullable', 'boolean'],
            'landing_cost_account_id'  => ['nullable', 'exists:chart_of_accounts,id'],

            // ── AR / AP accounts ──────────────────────────────────
            'ar_account_id'        => ['nullable', 'exists:chart_of_accounts,id'],
            'ap_account_id'        => ['nullable', 'exists:chart_of_accounts,id'],

            // ── Contacts repeater ─────────────────────────────────
            'contacts'             => ['nullable', 'array'],
            'contacts.*.id'        => ['nullable', 'integer'],
            'contacts.*.name_en'   => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.name_ar'   => ['nullable', 'string', 'max:255'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:100'],
            'contacts.*.email'     => ['nullable', 'email', 'max:255'],
            'contacts.*.phone'     => ['nullable', 'string', 'max:30'],
            'contacts.*.mobile'    => ['nullable', 'string', 'max:30'],
            'contacts.*.is_primary'=> ['nullable', 'boolean'],
        ];
    }
}
