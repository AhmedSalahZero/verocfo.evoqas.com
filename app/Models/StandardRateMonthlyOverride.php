<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  StandardRateMonthlyOverride
//
//  A one-month exception to a StandardRate's annual_rate. Kept as
//  its own table/model rather than columns on StandardRate so
//  recording "March was different" never means editing the annual
//  rate itself. See migration 000089 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, StandardRate
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardRateMonthlyOverride extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'standard_rate_id', 'year', 'month', 'rate', 'notes',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
    ];

    public function standardRate(): BelongsTo
    {
        return $this->belongsTo(StandardRate::class);
    }
}
