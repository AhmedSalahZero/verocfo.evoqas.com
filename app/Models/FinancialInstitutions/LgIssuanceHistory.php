<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuanceHistory
//
//  Append-only lifecycle log for one LgIssuance — renewal, decrease
//  (Advanced Payment LG), cancel, liquidate. See migration 000067
//  header for why one table covers all 4 event types.
//
//  @layer    Model
//  @depends  BelongsToCompany, LgIssuance, BankAccount, User
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class LgIssuanceHistory extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lg_issuance_history';

    protected $fillable = [
        'company_id',
        'lg_issuance_id',
        'event_type',
        'event_date',
        'old_end_date',
        'new_end_date',
        'renewal_fee_amount',
        'old_cash_cover_pct',
        'new_cash_cover_pct',
        'old_cash_cover_amount',
        'new_cash_cover_amount',
        'cash_cover_delta_amount',
        'old_commission_rate_pct',
        'new_commission_rate_pct',
        'old_commission_amount',
        'new_commission_amount',
        'decrease_amount',
        'balance_after_event',
        'old_current_amount',
        'shortfall_amount',
        'shortfall_account_id',
        'journal_entry_id',
        'notes',
        'created_by',
        'undone_at',
        'undone_by',
    ];

    protected $casts = [
        'event_date'           => 'date',
        'old_end_date'         => 'date',
        'new_end_date'         => 'date',
        'renewal_fee_amount'   => 'decimal:2',
        'old_cash_cover_pct'      => 'decimal:2',
        'new_cash_cover_pct'      => 'decimal:2',
        'old_cash_cover_amount'   => 'decimal:2',
        'new_cash_cover_amount'   => 'decimal:2',
        'cash_cover_delta_amount' => 'decimal:2',
        'old_commission_rate_pct' => 'decimal:4',
        'new_commission_rate_pct' => 'decimal:4',
        'old_commission_amount'   => 'decimal:2',
        'new_commission_amount'   => 'decimal:2',
        'decrease_amount'      => 'decimal:2',
        'balance_after_event'  => 'decimal:2',
        'old_current_amount'   => 'decimal:2',
        'shortfall_amount'     => 'decimal:2',
        'undone_at'            => 'datetime',
    ];

    public function lgIssuance(): BelongsTo       { return $this->belongsTo(LgIssuance::class); }
    public function shortfallAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'shortfall_account_id'); }
    public function createdBy(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }
    public function undoneBy(): BelongsTo         { return $this->belongsTo(User::class, 'undone_by'); }
    public function journalEntry(): BelongsTo     { return $this->belongsTo(JournalEntry::class); }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }
}
