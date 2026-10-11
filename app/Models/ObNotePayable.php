<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

// ══════════════════════════════════════════════════════════════════
//  ObNotePayable — Check Issued Opening Balance
//  status: issued | presented
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObNotePayable extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'ob_notes_payable';

    const STATUS_ISSUED               = 'issued';
    const STATUS_RECEIVED_BY_SUPPLIER = 'received_by_supplier';

    protected $fillable = [
        'company_id', 'partner_id', 'check_number', 'drawl_bank',
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
}