<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

// ══════════════════════════════════════════════════════════════════
//  ObPartnerInvoice — AR/AP Invoice Opening Balance
//  net_balance = invoice_amount - previous_collection
//  Drives AR/AP account balances in ob_coa_balances automatically.
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObPartnerInvoice extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'ob_partner_invoices';

    const TYPE_CUSTOMER = 'customer';
    const TYPE_SUPPLIER = 'supplier';

    protected $fillable = [
        'company_id', 'partner_id', 'partner_type', 'account_id',
        'invoice_ref', 'invoice_date', 'due_date',
        'invoice_amount', 'previous_collection', 'net_balance',
        'currency_id', 'amount_foreign', 'exchange_rate', 'notes',
    ];

    protected $casts = [
        'invoice_date'        => 'date',
        'due_date'            => 'date',
        'invoice_amount'      => 'decimal:4',
        'previous_collection' => 'decimal:4',
        'net_balance'         => 'decimal:4',
        'amount_foreign'      => 'decimal:4',
        'exchange_rate'       => 'decimal:6',
    ];

    // net_balance auto-computed on save
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->net_balance = bcsub(
                (string) $model->invoice_amount,
                (string) ($model->previous_collection ?? 0),
                4
            );
        });
    }

    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function account(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }

    public function isOverdue(string $asOfDate): bool
    {
        return $this->due_date->lt(\Carbon\Carbon::parse($asOfDate));
    }
}
