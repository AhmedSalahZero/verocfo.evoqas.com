<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  ObCoaBalance — COA Account Opening Balance
//  One row per postable account.
//  auto_populated = true: system filled from AR/AP/inventory data.
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObCoaBalance extends Model
{
    use BelongsToCompany;

    protected $table = 'ob_coa_balances';

    protected $fillable = [
        'company_id', 'account_id', 'entry_side', 'amount',
        'currency_id', 'amount_foreign', 'exchange_rate',
        'auto_populated', 'notes',
    ];

    protected $casts = [
        'amount'          => 'decimal:4',
        'amount_foreign'  => 'decimal:4',
        'exchange_rate'   => 'decimal:6',
        'auto_populated'  => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
