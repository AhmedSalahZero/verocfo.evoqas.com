<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000202_alter_partners_add_landing_cost_fields
//
//  Landing Cost feature — see VeroCFO_Landing_Cost_Design_v1_0.md.
//  Two new fields, meaningful for Supplier-type partners only, but
//  not enforced at the schema level (same "not a hard schema wall"
//  principle already used for bill_type on supplier_bills):
//
//    is_landing_cost_supplier  — checkbox on the Partner form. Purely
//                                additive: a partner can be a normal
//                                Supplier (Purchase Order → GRN →
//                                Supplier Bill) AND a Landing Cost
//                                Supplier at the same time. Confirmed
//                                with Mahmoud — not exclusive.
//    landing_cost_account_id   — which 146xxx Landing Cost Clearing
//                                sub-account this supplier defaults
//                                to. Suggested default ONLY, never
//                                enforced — same non-enforced-default
//                                pattern as Other Collection/Other
//                                Payment (Building Bible Rule 36.2).
//
//  @layer    Migration
//  @depends  partners, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_landing_cost_supplier')->default(false)->after('types');
            $table->foreignId('landing_cost_account_id')->nullable()
                ->after('is_landing_cost_supplier')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landing_cost_account_id');
            $table->dropColumn('is_landing_cost_supplier');
        });
    }
};
