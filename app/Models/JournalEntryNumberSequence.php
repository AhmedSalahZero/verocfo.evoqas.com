<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JournalEntryNumberSequence
//
//  ACC-004 fix (confirmed with Mahmoud, 2026-09) — one row per
//  company per calendar year/month, holding the last journal entry
//  number issued for that period. Exists purely so
//  JournalEntryService::generateEntryNumber() has a real row to lock
//  (lockForUpdate) before reading and incrementing it — a plain
//  COUNT(*) has no row to lock, which is exactly how two people
//  posting at the same instant could previously read the same "next
//  number" and collide. No global CompanyScope — company_id is always
//  filtered explicitly by the service, same pattern already used for
//  system-wide tables elsewhere in this codebase.
//
//  @layer    Model
//  @depends  Company
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryNumberSequence extends Model
{
    protected $fillable = [
        'company_id',
        'year',
        'month',
        'last_number',
    ];

    protected $casts = [
        'year'        => 'integer',
        'month'       => 'integer',
        'last_number' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
