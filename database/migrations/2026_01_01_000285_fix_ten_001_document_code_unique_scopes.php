<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000285_fix_ten_001_document_code_unique_scopes
//
//  TEN-001 (Audit, 8 Sep 2026). Same bug as 000162, on the five
//  document tables created after that migration and never brought
//  in line with it: a document code generated PER COMPANY PER MONTH
//  was made unique across the whole platform, so a second company's
//  first document of a given type in a given month collides with
//  the first company's and is rejected outright.
//
//    customer_debit_notes.debit_note_code   → CustomerDebitNoteService
//    supplier_debit_notes.debit_note_code   → SupplierDebitNoteService
//    supplier_credit_notes.credit_note_code → SupplierCreditNoteService
//    landing_costs.landing_cost_code        → LandingCostService
//    internal_settlements.settlement_code   → InternalSettlementService
//
//  Identical shape to every prior fix of this kind: drop the plain
//  column-level unique index, add a compound (company_id, code)
//  unique index instead. No data migration needed — this codebase
//  is still on test/demo data only (confirmed with Mahmoud,
//  8 Sep 2026), so there is nothing to de-duplicate first.
//
//  @layer    Migration
//  @depends  customer_debit_notes, supplier_debit_notes,
//            supplier_credit_notes, landing_costs, internal_settlements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_debit_notes', function (Blueprint $table) {
            $table->dropUnique('customer_debit_notes_debit_note_code_unique');
            $table->unique(['company_id', 'debit_note_code']);
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->dropUnique('supplier_debit_notes_debit_note_code_unique');
            $table->unique(['company_id', 'debit_note_code']);
        });

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropUnique('supplier_credit_notes_credit_note_code_unique');
            $table->unique(['company_id', 'credit_note_code']);
        });

        Schema::table('landing_costs', function (Blueprint $table) {
            $table->dropUnique('landing_costs_landing_cost_code_unique');
            $table->unique(['company_id', 'landing_cost_code']);
        });

        Schema::table('internal_settlements', function (Blueprint $table) {
            $table->dropUnique('internal_settlements_settlement_code_unique');
            $table->unique(['company_id', 'settlement_code']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_debit_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'debit_note_code']);
            $table->unique('debit_note_code');
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'debit_note_code']);
            $table->unique('debit_note_code');
        });

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'credit_note_code']);
            $table->unique('credit_note_code');
        });

        Schema::table('landing_costs', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'landing_cost_code']);
            $table->unique('landing_cost_code');
        });

        Schema::table('internal_settlements', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'settlement_code']);
            $table->unique('settlement_code');
        });
    }
};
