<?php

namespace App\Models\Traits;

// ══════════════════════════════════════════════════════════════════
//  SerializesDateOnly
//
//  Fix (July 2026): Laravel's `'field' => 'date'` cast controls how a
//  value is PARSED from the database (as a Carbon instance at
//  midnight) — it does NOT change how that value is serialized to
//  JSON/array for the frontend. Eloquent's default serializeDate()
//  always calls Carbon::toJSON(), which includes a full timestamp
//  (e.g. "2026-07-07T00:00:00.000000Z") regardless of cast type.
//  That's what was showing up in the Journal Entries list instead of
//  a plain "2026-07-07".
//
//  Apply this trait to any model with a genuinely date-only field
//  (no meaningful time component) — it formats every date sent to
//  the frontend as Y-m-d, full stop. This is the general rule going
//  forward for any new date-only field: don't rely on the cast alone
//  to hide the time, add this trait.
//
//  @layer    Trait
// ══════════════════════════════════════════════════════════════════

use DateTimeInterface;

trait SerializesDateOnly
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }
}
