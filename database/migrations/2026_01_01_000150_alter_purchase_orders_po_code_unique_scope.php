<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000150_alter_purchase_orders_po_code_unique_scope
//
//  Fixes a scoping bug: po_code was made unique across the ENTIRE
//  system instead of unique per company. Since PO numbers are
//  generated per company (PO/YYYY/MM/00001 restarting at 1 for each
//  company every month), two different companies legitimately land
//  on the same number in the same month — and the old global-unique
//  rule wrongly rejected the second company's PO as a "duplicate."
//
//  This changes the rule to: po_code must be unique WITHIN a
//  company, not across all companies.
//
//  @layer    Migration
//  @depends  purchase_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_po_code_unique');
            $table->unique(['company_id', 'po_code']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'po_code']);
            $table->unique('po_code');
        });
    }
};
