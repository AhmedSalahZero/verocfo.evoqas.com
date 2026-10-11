<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000067_create_lg_issuance_history_table
//
//  Append-only event log for every lifecycle action on an issued LG
//  — same pattern already used for bank_account_terms_history and
//  partner_employee_history. Confirmed with Mahmoud: Renewal must
//  keep history (old end date → new end date + renewal fee), and
//  Advanced Payment LG's partial decreases need the same treatment
//  (each delivery-triggered decrease is its own dated record, not
//  just an overwrite of current_amount).
//
//  One table covers all 4 event types rather than 4 separate tables
//  — they share company_id/lg_issuance_id/event_date/notes, and a
//  single chronological timeline per LG is more useful on the
//  issuance's detail view than four separate lists to merge.
//  Columns irrelevant to a given event_type are simply left null.
//
//  @layer    Migration
//  @depends  lg_issuances, bank_accounts, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lg_issuance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lg_issuance_id')->constrained('lg_issuances')->cascadeOnDelete();

            $table->enum('event_type', ['renewal', 'decrease', 'cancel', 'liquidate']);
            $table->date('event_date');

            // Renewal
            $table->date('old_end_date')->nullable();
            $table->date('new_end_date')->nullable();
            $table->decimal('renewal_fee_amount', 15, 2)->nullable();

            // Decrease (Advanced Payment LG partial delivery)
            $table->decimal('decrease_amount', 15, 2)->nullable();
            $table->decimal('balance_after_event', 15, 2)->nullable(); // current_amount snapshot after this event

            // Liquidate
            $table->decimal('shortfall_amount', 15, 2)->nullable();
            $table->foreignId('shortfall_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'lg_issuance_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lg_issuance_history');
    }
};
