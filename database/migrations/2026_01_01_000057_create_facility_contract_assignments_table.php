<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000057_create_facility_contract_assignments_table
//
//  One-to-many contract collateral for OD Against Contract/SO
//  Assignment facilities (facility_type = 'contract_so', COA 223003).
//
//  Why a separate table instead of a single FK on credit_facilities:
//    Banks often take assignment of multiple contracts or sales orders
//    simultaneously as collateral for one OD line. Storing a single
//    collateral_project_id / collateral_so_id on the facility would
//    only ever capture one — this table captures any number.
//    (The old single-FK columns on credit_facilities remain untouched;
//    they are used by other facility types and cause no harm here.)
//
//  Key fields per assignment row:
//    partner_id        — the customer on the contract/SO (for display)
//    collateral_type   — 'project' or 'sales_order'
//    lending_pct       — the rate the bank agrees to lend as % of value
//    lending_amount    — contract_amount × lending_pct / 100 (stored)
//    max_lending_limit — the bank's hard cap for THIS specific contract,
//                        regardless of percentage calc. Effective lending
//                        = MIN(lending_amount, max_lending_limit).
//                        This cap is per-assignment, not per-facility,
//                        because banks negotiate different limits for
//                        different contracts on the same OD line.
//    assignment_date   — when the bank took the legal assignment
//
//  Collateral validation (Rule 30) runs at service layer:
//    - collateral must be active
//    - collateral end_date must be > facility review_date
//    - soft warning if end_date is within 30 days of review_date
//
//  @layer    Migration
//  @depends  credit_facilities, companies, projects, sales_orders, partners
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_contract_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('credit_facility_id')
                  ->constrained('credit_facilities')
                  ->cascadeOnDelete();

            // The customer who owns the contract / SO being assigned
            $table->foreignId('partner_id')
                  ->constrained('partners')
                  ->restrictOnDelete();

            // Exactly one of these two will be set per row
            $table->enum('collateral_type', ['project', 'sales_order']);
            $table->unsignedBigInteger('collateral_project_id')->nullable();
            $table->unsignedBigInteger('collateral_so_id')->nullable();

            // Assignment terms — negotiated per contract, not per facility
            $table->date('assignment_date');
            $table->decimal('lending_pct', 5, 2);         // e.g. 75.00 means 75%
            $table->decimal('lending_amount', 15, 2);     // stored: contract_amount × lending_pct / 100
            $table->decimal('max_lending_limit', 15, 2);  // bank hard cap for this contract

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // ── Indexes ──────────────────────────────────────────
            $table->index(['company_id'], 'fca_company_idx');
            $table->index(['company_id', 'credit_facility_id'], 'fca_company_facility_idx');
            $table->index(['company_id', 'partner_id'], 'fca_company_partner_idx');
            $table->index(['company_id', 'collateral_project_id'], 'fca_company_project_idx');
            $table->index(['company_id', 'collateral_so_id'], 'fca_company_so_idx');

            // ── FK constraints for collateral ────────────────────
            // These are separate from foreignId() because the columns
            // are nullable — Laravel's foreignId() macro doesn't support
            // nullable FKs cleanly without a workaround.
            $table->foreign('collateral_project_id', 'fca_project_fk')
                  ->references('id')->on('projects')
                  ->restrictOnDelete();

            $table->foreign('collateral_so_id', 'fca_so_fk')
                  ->references('id')->on('sales_orders')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_contract_assignments');
    }
};
