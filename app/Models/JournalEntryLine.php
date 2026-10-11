<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JournalEntryLine
//
//  A single debit or credit row on a JournalEntry. Exactly one of
//  debit/credit is non-zero per line — enforced in
//  JournalEntryService::post(), not here.
//
//  @layer    Model
//  @depends  BelongsToCompany, JournalEntry, ChartOfAccount, CostCenter,
//            Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'journal_entry_id', 'chart_of_account_id',
        'cost_center_id', 'debit', 'credit', 'description', 'sort_order',
        'currency_id', 'amount_currency', 'exchange_rate',
    ];

    protected $casts = [
        'debit'  => 'decimal:2',
        'credit' => 'decimal:2',
        'amount_currency' => 'decimal:2',
        'exchange_rate'   => 'decimal:6',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isDebit(): bool
    {
        return bccomp((string) $this->debit, '0', 2) > 0;
    }
}
