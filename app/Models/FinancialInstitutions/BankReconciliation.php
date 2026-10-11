<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliation
//
//  One reconciliation of one bank account up to a statement date and
//  the bank's closing balance (audit H-25). 'draft' while being
//  worked on, 'completed' once the difference is exactly zero — the
//  report figures are then frozen on the row.
//
//  @layer    Model
//  @depends  BelongsToCompany, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Currency;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'company_id', 'bank_account_id', 'currency_id', 'statement_date', 'statement_ending_balance',
        'status', 'book_balance', 'outstanding_deposits', 'outstanding_payments',
        'unbooked_bank_items', 'booked_after_date', 'difference',
        'completed_at', 'completed_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'statement_date'           => 'date',
        'statement_ending_balance' => 'decimal:2',
        'book_balance'             => 'decimal:2',
        'outstanding_deposits'     => 'decimal:2',
        'outstanding_payments'     => 'decimal:2',
        'unbooked_bank_items'      => 'decimal:2',
        'booked_after_date'        => 'decimal:2',
        'difference'               => 'decimal:2',
        'completed_at'             => 'datetime',
    ];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function currency(): BelongsTo    { return $this->belongsTo(Currency::class); }
    public function createdBy(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }
    public function items(): HasMany         { return $this->hasMany(BankReconciliationItem::class); }

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
}
