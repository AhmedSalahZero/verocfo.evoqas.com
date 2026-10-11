<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000297 — two new permission rights: Post and Approve
//
//  AUDIT H3 (Sept 2026) — segregation of duties. "Post" used the same
//  right as "Edit", and nothing required a second person. Two new
//  flags per user / module / feature:
//
//    can_post    — may POST a document to the ledger (invoices, bills,
//                  GRNs, delivery notes, payroll, …) and may RECORD a
//                  payment or collection (which posts it immediately).
//    can_approve — may APPROVE someone else's request: a manual journal
//                  entry, an expense claim, reopening a posted invoice
//                  or bill. Nobody can approve their own request.
//
//  Existing users keep working exactly as today: can_post starts equal
//  to can_edit. can_approve starts OFF for everyone — the Company Admin
//  (who always has every right) grants it to the chosen approvers.
//
//  @layer    Migration
//  @depends  user_module_permissions
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_module_permissions', function (Blueprint $table) {
            $table->boolean('can_post')->default(false)->after('can_edit');
            $table->boolean('can_approve')->default(false)->after('can_reverse');
        });

        DB::table('user_module_permissions')->update(['can_post' => DB::raw('can_edit')]);
    }

    public function down(): void
    {
        Schema::table('user_module_permissions', function (Blueprint $table) {
            $table->dropColumn(['can_post', 'can_approve']);
        });
    }
};
