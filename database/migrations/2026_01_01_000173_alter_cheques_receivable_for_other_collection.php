<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000173_alter_cheques_receivable_for_other_collection
//
//  Per Mahmoud (August 2026): a cheque received through Other
//  Collection is NOT a separate list from cheques received from
//  customers — it is the exact same "money owed to us on a cheque"
//  bucket, just originating from a different kind of collection. One
//  screen, one table, no duplicate tracking mechanism.
//
//  customer_payment_id was a mandatory, unique, cascading FK
//  (migration 000126) — that made "belongs to a Customer Collection"
//  the only possible source. This migration widens the table to
//  accept EITHER source:
//    customer_payment_id  → now nullable (was mandatory)
//    other_collection_id  → new, nullable, unique (same 1:1 shape)
//  Exactly one of the two must be set — enforced in
//  ChequeReceivableService, not the schema (MySQL has no clean native
//  "exactly one of two nullable FKs" constraint, and this codebase's
//  established pattern elsewhere is app-layer enforcement over
//  database CHECK constraints for this kind of either/or rule).
//
//  partner_id, drawee_bank_id, cheque_amount, cheque_number,
//  due_date, and every downstream lifecycle field (sent_for_
//  collection/collected/rejected) are already source-agnostic — they
//  describe the physical cheque, not who it came from — so nothing
//  else on this table needs to change.
//
//  @layer    Migration
//  @depends  cheques_receivable, other_collections
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->dropForeign(['customer_payment_id']);
            $table->dropUnique(['customer_payment_id']);
        });

        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->foreignId('customer_payment_id')->nullable()->change();
            $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->cascadeOnDelete();

            $table->foreignId('other_collection_id')->nullable()->after('customer_payment_id')
                ->constrained('other_collections')->cascadeOnDelete();
        });

        // Re-declare as two separate 1:1 uniques (each nullable FK can
        // repeat NULL freely across rows — MySQL allows multiple NULLs
        // in a unique index, only actual duplicate values are rejected —
        // so this correctly still guarantees "at most one cheque row per
        // Customer Collection" / "at most one cheque row per Other
        // Collection" without blocking the many rows that leave one side
        // NULL.
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->unique('customer_payment_id', 'cheq_recv_customer_payment_unique');
            $table->unique('other_collection_id', 'cheq_recv_other_collection_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cheques_receivable', function (Blueprint $table) {
            $table->dropUnique('cheq_recv_customer_payment_unique');
            $table->dropUnique('cheq_recv_other_collection_unique');
            $table->dropForeign(['other_collection_id']);
            $table->dropColumn('other_collection_id');

            $table->dropForeign(['customer_payment_id']);
            $table->foreignId('customer_payment_id')->nullable(false)->change();
            $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->cascadeOnDelete();
            $table->unique('customer_payment_id');
        });
    }
};
