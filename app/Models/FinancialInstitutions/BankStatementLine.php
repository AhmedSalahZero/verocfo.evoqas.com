<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankStatementLine
//
//  One line of the BANK'S OWN statement for a bank account (audit H-25).
//  Amount is in the account's own currency, signed: + money in,
//  − money out. Matched/unmatched is derived from
//  bank_reconciliation_items, never stored here.
//
//  @layer    Model
//  @depends  BelongsToCompany, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementLine extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'bank_account_id', 'line_date', 'description', 'reference',
        'amount', 'line_hash', 'source', 'created_by',
    ];

    protected $casts = [
        'line_date' => 'date',
        'amount'    => 'decimal:2',
    ];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function items(): HasMany          { return $this->hasMany(BankReconciliationItem::class, 'bank_statement_line_id'); }
}
