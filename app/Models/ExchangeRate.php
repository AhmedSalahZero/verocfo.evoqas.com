<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ExchangeRate
//
//  Stores daily exchange rates for each active currency against
//  the company's functional currency.
//
//  Append-only — rates are NEVER updated or deleted.
//  Each rate has a rate_date (effective from that date).
//  The system always uses the most recent rate where
//  rate_date <= transaction_date.
//
//  rateFor(int $companyId, int $currencyId, string $date):
//  Static helper used by the posting engine to resolve the
//  correct exchange rate for any transaction date.
//
//  Functional currency always has rate = 1.000000 implicitly.
//  The posting engine never queries exchange_rates for the
//  functional currency — it uses 1.000000 directly.
//
//  source: 'manual' in Phase 1.
//          'cbe_api' planned for Phase 2 (Central Bank of Egypt API).
//
//  @layer    Model
//  @depends  BelongsToCompany, Currency, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class ExchangeRate extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // Append-only — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'currency_id',
        'rate',
        'rate_date',
        'source',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'rate'       => 'decimal:6',
        'rate_date'  => 'date',
        'created_at' => 'datetime',
    ];

    // ── Source Constants ──────────────────────────────────────────

    const SOURCE_MANUAL  = 'manual';
    const SOURCE_CBE_API = 'cbe_api'; // Phase 2

    // ── Rate Resolution ───────────────────────────────────────────

    /**
     * Returns the exchange rate for a currency on or before a given date.
     * Used by the posting engine for every foreign currency transaction.
     *
     * Returns '1.000000' if the currency is the functional currency.
     * Returns null if no rate exists — caller must handle this case
     * (typically block the transaction with a clear error message).
     */
    public static function rateFor(
        int    $companyId,
        int    $currencyId,
        string $date
    ): ?string {
        $rate = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('currency_id', $currencyId)
            ->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->value('rate');

        return $rate ? (string) $rate : null;
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
