<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CompanyTaxSetting
//
//  One row per company — stores default VAT and Debit WHT rates.
//  Used as fallback when no category or product override exists.
//
//  Account mapping (fixed — no user selection required):
//    VAT Output  → COA code 226xxx
//    VAT Input   → COA code 137
//    Debit WHT   → COA code 142
//
//  @layer    Model
//  @depends  BelongsToCompany
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyTaxSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'company_tax_settings';

    protected $fillable = [
        'company_id',
        'vat_rate',
        'debit_wht_rate',
    ];

    protected $casts = [
        'vat_rate'       => 'decimal:2',
        'debit_wht_rate' => 'decimal:2',
    ];
}
