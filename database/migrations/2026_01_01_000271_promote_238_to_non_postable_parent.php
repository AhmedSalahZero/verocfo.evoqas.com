<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000271_promote_238_to_non_postable_parent
//
//  Section 7 (Applied vs Actual — month-end variance closing).
//  Migration 000223 originally seeded 238 Manufacturing Overhead
//  Absorbed as ONE directly-postable account, shared by every
//  Overhead Pool — correct at the time, per that migration's own
//  header ("Deliberately ONE account each"). That no longer holds
//  now that overhead variance reconciles PER POOL (confirmed with
//  Mahmoud) — this migration flips 238 to a non-postable PARENT so
//  each pool can get its own postable child underneath
//  (OverheadPoolService::ensureAppliedAccount()).
//
//  Per Rule 5.1 (never modify an existing migration), this is a new
//  migration rather than an edit to 000223 — ChartOfAccountSeeder.php
//  is also updated in the same commit so a fresh install seeds 238
//  correctly from day one; this migration is what fixes an install
//  that already ran 000223 before this change existed.
//
//  Data-only, same reasoning as 000223 itself: 238 is a shared
//  system-wide row (company_id = NULL) — one update reaches every
//  company immediately, no per-company backfill needed.
//
//  @layer    Migration
//  @depends  chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '238')
            ->update(['is_postable' => false]);
    }

    public function down(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '238')
            ->update(['is_postable' => true]);
    }
};
