<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000054_create_cbe_corridor_rates_table
//
//  CBE Corridor Rate history — platform level (company_id = NULL).
//  One row per rate change event.
//
//  When a new rate is saved:
//    1. All credit_facilities where follows_corridor_rate = true
//       get their borrowing_rate updated
//    2. interest_rate recalculated (borrowing + margin)
//    3. facility_daily_balances from effective_date onwards
//       recalculated with new daily interest using new rate
//    4. Audit log entry created
//
//  Interest split logic (Option A — agreed):
//    Days before effective_date  → old rate
//    Days from effective_date on → new rate
//    Monthly interest = sum of all daily amounts (360-day convention)
//
//  @layer    Migration
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbe_corridor_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate', 8, 4);                         // the new corridor rate %
            $table->decimal('previous_rate', 8, 4)->nullable();    // previous rate for reference
            $table->date('effective_date');                        // rate applies FROM this date
            $table->string('direction', 10)->nullable();           // 'increase' | 'decrease' | 'unchanged'
            $table->text('notes')->nullable();                     // e.g. "CBE MPC decision May 2026"
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['effective_date']);                     // for range queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbe_corridor_rates');
    }
};
