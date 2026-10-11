<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000325 — accounts 227 and 241
//
//  AUDIT L-9. Letters of guarantee stay off the balance sheet, so
//  227 is no longer a posting account. End of service benefits are
//  a long-term liability, so 241 moves under 21.
//
//  @layer    Migration
//  @depends  chart_of_accounts
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $longTermId = DB::table('chart_of_accounts')->where('code', '21')->whereNull('company_id')->value('id');

        DB::table('chart_of_accounts')->where('code', '227')->update(['is_postable' => false]);

        if ($longTermId) {
            DB::table('chart_of_accounts')->where('code', '241')->update([
                'parent_id'    => $longTermId,
                'account_type' => 'Long-term Liabilities',
            ]);
        }
    }

    public function down(): void
    {
        $currentId = DB::table('chart_of_accounts')->where('code', '22')->whereNull('company_id')->value('id');

        DB::table('chart_of_accounts')->where('code', '227')->update(['is_postable' => true]);

        if ($currentId) {
            DB::table('chart_of_accounts')->where('code', '241')->update([
                'parent_id'    => $currentId,
                'account_type' => 'Current Liabilities',
            ]);
        }
    }
};
