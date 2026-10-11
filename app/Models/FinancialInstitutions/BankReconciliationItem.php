<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliationItem
//
//  A ledger line ("book item") cleared in a bank reconciliation —
//  matched to a statement line, or ticked as present on the bank's
//  statement (bank_statement_line_id null). A ledger line can be
//  cleared only once, ever (unique index).
//
//  @layer    Model
//  @depends  BelongsToCompany, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntryLine;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bank_reconciliation_id', 'journal_entry_line_id',
        'bank_statement_line_id', 'amount', 'matched_by', 'created_by',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function reconciliation(): BelongsTo { return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id'); }
    public function journalEntryLine(): BelongsTo { return $this->belongsTo(JournalEntryLine::class); }
    public function statementLine(): BelongsTo   { return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id'); }
}
