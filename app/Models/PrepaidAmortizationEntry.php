<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PrepaidAmortizationEntry
//
//  One row per period's automatic prepaid-expense amortization
//  posting (Dr the line's own expense account / Cr 136 Prepaid
//  Expenses). Deliberate mirror of
//  App\Models\FinancialInstitutions\LeaseWearDownEntry — same
//  click-per-period pattern, same reverse-never-delete rule. See
//  PrepaidAmortizationService for the calculation (including the
//  mid-month convention) and migration 000193 for the full rationale.
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierBillLine, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrepaidAmortizationEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'supplier_bill_line_id', 'period_number', 'entry_date', 'amount',
        'journal_entry_id', 'reversal_journal_entry_id', 'status',
        'posted_by', 'reversed_by', 'reversed_at',
    ];

    protected $casts = [
        'entry_date'  => 'date',
        'amount'      => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function supplierBillLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBillLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }
}
