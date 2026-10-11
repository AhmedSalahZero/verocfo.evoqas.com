<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000314_cheque_batches_and_payable_void   (audit M-31)
//
//  1. cheques_receivable: a collection may now hold SEVERAL cheques
//     (a batch of post-dated cheques — normal Egyptian practice). The
//     one-cheque-per-collection unique indexes are replaced with plain
//     indexes (MySQL needs an index behind each foreign key, so the
//     plain index is created BEFORE the unique one is dropped).
//  2. cheques_payable: an issued company cheque can now be marked
//     'void' or 'stopped' (with reason, date, user) instead of being
//     stuck at 'issued' forever.
//
//  @layer    Migration
//  @depends  cheques_receivable, cheques_payable
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->index('customer_payment_id', 'cheq_recv_customer_payment_idx');
            $table->index('other_collection_id', 'cheq_recv_other_collection_idx');
        });
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->dropUnique('cheq_recv_customer_payment_unique');
            $table->dropUnique('cheq_recv_other_collection_unique');
        });

        DB::statement("ALTER TABLE cheques_payable MODIFY status ENUM('issued','paid','void','stopped') NOT NULL DEFAULT 'issued'");

        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->string('void_reason', 255)->nullable()->after('paid_at');
            $table->date('voided_at')->nullable()->after('void_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cheques_payable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['void_reason', 'voided_at']);
        });
        DB::statement("UPDATE cheques_payable SET status='issued' WHERE status IN ('void','stopped')");
        DB::statement("ALTER TABLE cheques_payable MODIFY status ENUM('issued','paid') NOT NULL DEFAULT 'issued'");
    }
};
