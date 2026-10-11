<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000298 — approval requests + control accounts
//
//  AUDIT H3 / H5 (Sept 2026), decisions confirmed with Mahmoud:
//
//  1. manual_journal_requests — EVERY manual journal entry now waits
//     here as "pending" until a DIFFERENT user with the Approve right
//     approves it. Only then is the real journal entry posted. It is
//     kept out of journal_entries on purpose: every report sums that
//     table, so a pending entry must never be in it.
//
//  2. document_reopen_requests — "Set to Draft" on a POSTED customer
//     invoice or supplier bill (tax documents) now needs a written
//     reason and a DIFFERENT approver, and only in an open month. The
//     request waits here until approved (the reopen then happens) or
//     rejected.
//
//  3. chart_of_accounts.blocks_manual_posting — control accounts that
//     only their own sub-ledger may move (AR 133, Notes Receivable 134,
//     Inventory 135, Accrued Revenue 143, AP 221, Notes Payable 222,
//     GRNI 233, Deferred Revenue 236, Labour / Overhead clearing
//     237 / 238). A manual journal entry to any of them is refused —
//     corrections go through the proper document (credit note, stock
//     adjustment, …). Set for the existing accounts and every existing
//     sub-account; new sub-accounts inherit it from their parent.
//
//  @layer    Migration
//  @depends  companies, users, journal_entries, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const CONTROL_PREFIXES = ['133', '134', '135', '143', '221', '222', '233', '236', '237', '238'];

    public function up(): void
    {
        Schema::create('manual_journal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('entry_date');
            $table->text('narration');
            $table->json('debit_lines');
            $table->json('credit_lines');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('document_reopen_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('document_type', 40); // customer_invoice | supplier_bill
            $table->unsignedBigInteger('document_id');
            $table->string('document_code', 60);
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['document_type', 'document_id']);
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('blocks_manual_posting')->default(false)->after('is_postable');
        });

        DB::table('chart_of_accounts')
            ->where(function ($q) {
                foreach (self::CONTROL_PREFIXES as $prefix) {
                    $q->orWhere('code', 'like', $prefix.'%');
                }
            })
            ->update(['blocks_manual_posting' => true]);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('blocks_manual_posting');
        });
        Schema::dropIfExists('document_reopen_requests');
        Schema::dropIfExists('manual_journal_requests');
    }
};
