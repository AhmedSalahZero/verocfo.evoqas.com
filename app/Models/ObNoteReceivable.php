<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

// ══════════════════════════════════════════════════════════════════
//  ObNoteReceivable — Check Received Opening Balance
//  status: in_safe | under_collection
//  bank_account_id required when status = under_collection
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObNoteReceivable extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'ob_notes_receivable';

    const STATUS_IN_SAFE           = 'in_safe';
    const STATUS_UNDER_COLLECTION  = 'under_collection';

    protected $fillable = [
        'company_id', 'partner_id', 'check_number', 'drawee_bank',
        'check_date', 'due_date', 'is_post_dated',
        'amount', 'currency_id', 'amount_foreign', 'exchange_rate',
        'status', 'bank_account_id', 'notes',
    ];

    protected $casts = [
        'check_date'     => 'date',
        'due_date'       => 'date',
        'is_post_dated'  => 'boolean',
        'amount'         => 'decimal:4',
        'amount_foreign' => 'decimal:4',
        'exchange_rate'  => 'decimal:6',
    ];

    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'bank_account_id'); }

    public function isUnderCollection(): bool { return $this->status === self::STATUS_UNDER_COLLECTION; }
}
