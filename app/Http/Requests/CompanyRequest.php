<?php

namespace App\Http\Requests;

// ══════════════════════════════════════════════════════════════════
//  CompanyRequest
//
//  Validates all input for creating and updating a Company.
//  Updated v1.1: opening_balance_date added as required field.
//  Required on create — must be before or equal to today.
//  Used to auto-generate the first fiscal year (Rule 25.10).
//
//  @layer    FormRequest
//  @depends  App\Models\Company, App\Models\BusinessSector
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name_en'                  => ['required', 'string', 'max:255'],
            'name_ar'                  => ['nullable', 'string', 'max:255'],
            'company_type'             => ['required', 'array', 'min:1'],
            'company_type.*'           => ['string', 'in:Service,Trading,Manufacturing,Service with Inventory'],
            'grn_policy'               => ['required', 'string', 'in:po_required,allow_ad_hoc'],
            'business_sector_id'       => ['nullable', 'integer', 'exists:business_sectors,id'],
            'legal_structure'          => ['nullable', 'string', 'in:Joint Stock Company,Limited Liability Company,Sole Proprietorship,Partnership,Limited Partnership,Branch of Foreign Company,Holding Company,Free Zone Company'],
            'base_currency'            => ['required', 'string', 'size:3'],
            'fiscal_year_start_month'  => ['required', 'integer', 'between:1,12'],
            // Required on create — drives auto-creation of first fiscal year
            'opening_balance_date'     => [$isCreate ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'country'                  => ['nullable', 'string', 'max:50'],
            'timezone'                 => ['nullable', 'string', 'max:50'],
            'phone'                    => ['nullable', 'string', 'max:30'],
            'address'                  => ['nullable', 'string', 'max:255'],
            'tax_registration_number'  => ['nullable', 'string', 'max:50'],
            'subscription_tier'        => ['required', 'string', 'in:Starter,Growth,Pro,Enterprise'],
            'additional_users'         => ['nullable', 'integer', 'min:0'],
            'subscription_expires_at'  => ['nullable', 'date'],
            'is_active'                => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name_en'                 => 'Company Name (English)',
            'name_ar'                 => 'Company Name (Arabic)',
            'company_type'            => 'Company Type',
            'business_sector_id'      => 'Business Sector',
            'legal_structure'         => 'Legal Structure',
            'base_currency'           => 'Base Currency',
            'fiscal_year_start_month' => 'Fiscal Year Start Month',
            'opening_balance_date'    => 'Opening Balance Date',
            'subscription_tier'       => 'Subscription Tier',
            'subscription_expires_at' => 'Subscription Expiry Date',
        ];
    }
}
