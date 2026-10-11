<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000292 — cbe_corridor_rates gets company_id
//
//  AUDIT H6 FIX (Sept 2026, corrected with Mahmoud).
//  Each company manages ITS OWN corridor rate for ITS OWN facilities.
//  The table had no company_id, so a rate posted by Company A became
//  every other company's "current rate" and history too.
//
//  What this migration does:
//    1. Adds company_id (FK → companies, deleted with the company) and
//       an index on (company_id, effective_date).
//    2. Existing rows → the company of the user who posted them
//       (created_by → users.company_id).
//    3. Rows posted by the Super Admin (no company) → COPIED into every
//       company, so no company loses its history, then the original
//       company-less row is removed. (Agreed default with Mahmoud.)
//
//  Written with plain queries in a PHP loop (no MySQL-only UPDATE …
//  JOIN) so it also runs on the SQLite test database.
//
//  @layer    Migration
//  @depends  companies, users, cbe_corridor_rates
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                  ->constrained('companies')->cascadeOnDelete();
            $table->index(['company_id', 'effective_date']);
        });

        $userCompany = DB::table('users')->pluck('company_id', 'id');
        $companyIds  = DB::table('companies')->pluck('id');

        foreach (DB::table('cbe_corridor_rates')->orderBy('id')->get() as $row) {
            $companyId = $userCompany[$row->created_by] ?? null;

            if ($companyId) {
                DB::table('cbe_corridor_rates')->where('id', $row->id)->update(['company_id' => $companyId]);
                continue;
            }

            // Posted by the Super Admin → a copy for every company.
            if ($companyIds->isEmpty()) {
                continue; // nothing to copy into — leave the row as it is
            }

            $copy = (array) $row;
            unset($copy['id']);

            foreach ($companyIds as $id) {
                DB::table('cbe_corridor_rates')->insert(array_merge($copy, ['company_id' => $id]));
            }

            DB::table('cbe_corridor_rates')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('cbe_corridor_rates', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'effective_date']);
            $table->dropColumn('company_id');
        });
    }
};
