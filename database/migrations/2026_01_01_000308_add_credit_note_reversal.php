<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000308_add_credit_note_reversal
//
//  Audit M-9 — a posted Customer Credit Note could never be undone.
//  Adds the 'reversed' status and who / when / why it was reversed.
//  The enum is only widened on MySQL (the real database).
//
//  @layer    Migration
//  @depends  credit_notes
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE credit_notes MODIFY COLUMN status ENUM('draft','posted','cancelled','reversed') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE credit_notes SET status = 'posted' WHERE status = 'reversed'");
            DB::statement("ALTER TABLE credit_notes MODIFY COLUMN status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
