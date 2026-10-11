<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankAccountTermsHistory
//
//  Append-only log of changes to interest_rate and
//  minimum_balance_for_interest on a current account. Banks change
//  these terms over time — this preserves what the rate/minimum
//  was, when it changed, and who recorded the change.
//
//  Same pattern as PartnerEmployeeHistory. Records are never
//  updated or deleted; written only by BankService when an actual
//  value change is detected.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class BankAccountTermsHistory extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'bank_account_terms_history';

    const UPDATED_AT = null; // append-only, no updated_at

    const FIELD_INTEREST_RATE   = 'interest_rate';
    const FIELD_MINIMUM_BALANCE = 'minimum_balance_for_interest';

    const TRACKED_FIELDS = [
        self::FIELD_INTEREST_RATE,
        self::FIELD_MINIMUM_BALANCE,
    ];

    protected $fillable = [
        'company_id',
        'bank_account_id',
        'field',
        'old_value',
        'new_value',
        'effective_date',
        'changed_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'changed_by');
    }
}
