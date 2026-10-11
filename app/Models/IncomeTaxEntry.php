<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  IncomeTaxEntry
//
//  One corporate income tax event (Audit H19): a year-end tax
//  provision, or an offset of Debit WHT (142) against the tax owed
//  (226003). See migration 000302. Written only by IncomeTaxService;
//  never edited — cancelled (journal entry reversed) instead.
//
//  @layer    Model
//  @depends  BelongsToCompany, FiscalYear, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeTaxEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const TYPE_PROVISION  = 'provision';
    const TYPE_WHT_OFFSET = 'wht_offset';

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'fiscal_year_id', 'entry_type', 'entry_date', 'current_tax', 'deferred_tax',
        'status', 'journal_entry_id', 'notes', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'current_tax'  => 'decimal:2',
        'deferred_tax' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo   { return $this->belongsTo(FiscalYear::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
}
