<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FxRevaluationLine
//
//  One revalued item inside an FxRevaluationRun (Audit H12): a
//  foreign-currency account (bank, safe, loan, deposit…) or an open
//  customer invoice, supplier bill or cheque. Amounts are signed
//  debit − credit, so for a payable the balances are negative.
//  difference = revalued_balance − book_balance: positive is a gain
//  (debit to the item, credit 432), negative a loss (credit to the
//  item, debit 572).
//
//  @layer    Model
//  @depends  BelongsToCompany, FxRevaluationRun, ChartOfAccount, Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRevaluationLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fx_revaluation_run_id', 'item_type', 'item_id', 'item_label',
        'chart_of_account_id', 'currency_id', 'native_balance', 'book_balance',
        'closing_rate', 'revalued_balance', 'difference',
    ];

    protected $casts = [
        'native_balance'   => 'decimal:2',
        'book_balance'     => 'decimal:2',
        'closing_rate'     => 'decimal:6',
        'revalued_balance' => 'decimal:2',
        'difference'       => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FxRevaluationRun::class, 'fx_revaluation_run_id');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
