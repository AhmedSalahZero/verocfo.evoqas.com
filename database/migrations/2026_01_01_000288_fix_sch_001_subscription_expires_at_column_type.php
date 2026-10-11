<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000288_fix_sch_001_subscription_expires_at_column_type
//
//  SCH-001 (Audit, 8 Sep 2026). companies.subscription_expires_at was
//  declared as a MySQL TIMESTAMP, which cannot represent any date
//  past 2038-01-19 — a 20-year subscription set today (2026) already
//  fails outright ("Incorrect datetime value: '2046-12-31'").
//
//  Confirmed this column never needs a time-of-day component: the
//  Company model casts it to 'datetime' but every actual write in
//  the app — CompanyRequest's own validation ('date', not
//  'date_format'), every demo/seed script, every testing-cycle
//  scenario — only ever sets a plain calendar date ('2035-12-31',
//  etc.). Every other date-only column in this schema is DATE or
//  DATETIME, never TIMESTAMP; this one was the odd one out.
//
//  Changed to DATE — matches actual usage exactly, and DATE in MySQL
//  supports years 1000–9999, comfortably past any subscription term
//  this business will ever sell. Existing values (all well before
//  2038) convert cleanly; MySQL truncates the time-of-day component,
//  which every current row already has as 00:00:00 anyway.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('subscription_expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('subscription_expires_at')->nullable()->change();
        });
    }
};
