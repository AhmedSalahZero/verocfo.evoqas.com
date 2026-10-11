<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesReturn
//
//  Goods accepted back from a customer against a posted Delivery Note
//  (Audit H16). See migration 000305 and SalesReturnService. Posted on
//  creation; cancelled (everything reversed), never deleted.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, SalesOrder, DeliveryNote,
//            SalesReturnLine, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const TREATMENT_CREDIT  = 'credit';
    const TREATMENT_REPLACE = 'replace';

    protected $fillable = [
        'company_id', 'return_code', 'partner_id', 'sales_order_id', 'delivery_note_id', 'return_date',
        'invoiced_treatment', 'status', 'total_cost', 'accrual_reversed', 'revenue_deferred', 'inventory_journal_entry_id',
        'revenue_journal_entry_id', 'credit_note_ids', 'reason', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'return_date'      => 'date',
        'total_cost'       => 'decimal:2',
        'accrual_reversed' => 'decimal:2',
        'revenue_deferred' => 'decimal:2',
        'credit_note_ids'  => 'array',
        'cancelled_at'     => 'datetime',
    ];

    public function lines(): HasMany            { return $this->hasMany(SalesReturnLine::class); }
    public function partner(): BelongsTo        { return $this->belongsTo(Partner::class); }
    public function salesOrder(): BelongsTo     { return $this->belongsTo(SalesOrder::class); }
    public function deliveryNote(): BelongsTo   { return $this->belongsTo(DeliveryNote::class); }
    public function inventoryJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'inventory_journal_entry_id'); }
    public function revenueJournalEntry(): BelongsTo   { return $this->belongsTo(JournalEntry::class, 'revenue_journal_entry_id'); }
    public function creator(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }
}
