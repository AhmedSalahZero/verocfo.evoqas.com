<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetTypeRequest
//
//  Fixed Asset Types get their own dedicated, much simpler Settings
//  page — confirmed with Mahmoud after the combined-form design (a
//  4th tile on the regular Products & Services form) proved
//  confusing. No usage_types, no UOM, no barcode, no selling price —
//  just what a Fixed Asset actually needs: which category account it
//  posts to, and the defaults every asset created from it starts with.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;

class FixedAssetTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en'                     => ['required', 'string', 'max:255'],
            'name_ar'                     => ['nullable', 'string', 'max:255'],
            'description'                 => ['nullable', 'string'],
            // Audit M2: must be one of the postable 111xxx fixed-asset
            // accounts offered on the form, own company or system.
            'fixed_asset_account_id'      => ['required', 'integer', new ExistsForCompany(
                'chart_of_accounts',
                true,
                fn ($q) => $q->where('code', 'like', '111%')
                             ->where('code', '!=', '111')
                             ->where('is_postable', true)
            )],
            'default_useful_life_months'  => ['required', 'integer', 'min:1'],
            'default_residual_value'      => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
