<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CbeCorridorRate
//
//  History of CBE Corridor Rate changes.
//  COMPANY-OWNED (Audit H6, Sept 2026 — corrected with Mahmoud):
//  each company keeps its own corridor rate and history for its own
//  facilities. Previously platform-level with no company_id, so one
//  company's rate became every company's "current rate".
//  One row per rate change event.
//
//  When a new rate is saved, CbeCorridorRateService:
//    1. Saves this record
//    2. Updates all facilities where follows_corridor_rate = true:
//       borrowing_rate → new corridor rate
//       interest_rate  → new corridor rate + bank_margin_rate
//    3. Recalculates facility_daily_balances from effective_date
//       using new rate (daily split — Option A confirmed)
//    4. Writes audit log
//
//  current() / rateOnDate() take an optional $companyId. Pass it
//  explicitly from services; inside a normal request the company
//  filter (BelongsToCompany) applies anyway.
//
//  @layer    Model
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbeCorridorRate extends Model
{
    use \App\Models\Traits\SerializesDateOnly;
    use \App\Models\Traits\BelongsToCompany;

    protected $table = 'cbe_corridor_rates';

    protected $fillable = [
        'company_id',
        'rate', 'previous_rate', 'effective_date',
        'direction', 'notes', 'created_by',
    ];

    protected $casts = [
        'rate'           => 'decimal:4',
        'previous_rate'  => 'decimal:4',
        'effective_date' => 'date',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Static helpers ────────────────────────────────────────────

    /** Returns the currently active corridor rate (latest effective record) */
    public static function current(?int $companyId = null): ?self
    {
        return static::forCompany($companyId)
            ->where('effective_date', '<=', now()->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Returns the rate that was active on a specific date */
    public static function rateOnDate(string $date, ?int $companyId = null): ?string
    {
        return static::forCompany($companyId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('rate');
    }

    /** Query limited to one company when an id is given (Audit H6). */
    private static function forCompany(?int $companyId)
    {
        $query = static::query();

        return $companyId ? $query->where('company_id', $companyId) : $query;
    }

    public function directionLabel(): string
    {
        return match($this->direction) {
            'increase' => '▲ Increase',
            'decrease' => '▼ Decrease',
            default    => '— Unchanged',
        };
    }
}
