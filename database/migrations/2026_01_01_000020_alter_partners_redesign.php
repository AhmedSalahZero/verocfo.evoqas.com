<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000020_alter_partners_redesign
//
//  Redesigns the partners table to match the agreed architecture:
//
//  1. type (varchar) → types (JSON array)
//     A partner can be Customer + Supplier simultaneously,
//     or Shareholder + Employee, etc. Multi-select flags.
//     Valid values: customer | supplier | employee |
//                   shareholder | sister_company | other
//
//  2. payment_terms (varchar) → collection_term_id + payment_term_id
//     Both are FK to payment_terms table.
//     collection_term_id → used on Customer side (AR aging)
//     payment_term_id    → used on Supplier side (AP aging)
//
//  3. Add missing fields:
//     - business_sector_id (Customer + Supplier)
//     - sales_channel (Customer default, overridable on transaction)
//     - sister_company_name (free text for Sister Company type)
//     - cost_center_id (Employee type)
//     - equity_pct replaces shareholding_pct (rename for clarity)
//
//  4. Remove fields no longer in design:
//     - contribution_date (removed from Shareholder)
//     - customer_category (replaced by sales_zone + channel)
//     - daily_rate / hourly_rate (belong to HR module, not partner)
//     - relationship_type (replaced by sister_company_name)
//
//  @layer    Migration
//  @depends  partners, payment_terms, business_sectors, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {

            // ── 1. Add types JSON (replaces type varchar) ─────────
            $table->json('types')->nullable()->after('code');

            // ── 2. Add payment term FKs ───────────────────────────
            // collection_term_id → Customer side
            $table->foreignId('collection_term_id')
                  ->nullable()
                  ->after('credit_limit')
                  ->constrained('payment_terms')
                  ->nullOnDelete();

            // payment_term_id → Supplier side
            $table->foreignId('payment_term_id')
                  ->nullable()
                  ->after('collection_term_id')
                  ->constrained('payment_terms')
                  ->nullOnDelete();

            // ── 3. Add business_sector_id ─────────────────────────
            $table->foreignId('business_sector_id')
                  ->nullable()
                  ->after('payment_term_id')
                  ->constrained('business_sectors')
                  ->nullOnDelete();

            // ── 4. Add sales_channel ──────────────────────────────
            // Default sales channel for this customer.
            // Overridable on each Sales Order.
            // e.g. Direct | Distributor | Export | Online | Agent
            $table->string('sales_channel', 50)->nullable()->after('sales_zone');

            // ── 5. Add sister_company_name (free text) ────────────
            $table->string('sister_company_name')->nullable()->after('sales_channel');

            // ── 6. Add cost_center_id for Employee type ───────────
            $table->foreignId('cost_center_id')
                  ->nullable()
                  ->after('department')
                  ->constrained('cost_centers')
                  ->nullOnDelete();

            // ── 7. Add equity_pct (Shareholder type) ─────────────
            // Renamed from shareholding_pct for clarity
            $table->decimal('equity_pct', 5, 2)->nullable()->after('cost_center_id');

            // ── 8. Remove old fields no longer in design ──────────
            $table->dropColumn([
                'type',               // replaced by types JSON
                'payment_terms',      // replaced by FK columns above
                'customer_category',  // removed from design
                'daily_rate',         // belongs to HR module
                'hourly_rate',        // belongs to HR module
                'shareholding_pct',   // replaced by equity_pct
                'contribution_date',  // removed from design
                'relationship_type',  // replaced by sister_company_name
            ]);
        });

        // ── 9. Add new indexes ────────────────────────────────────
        Schema::table('partners', function (Blueprint $table) {
            $table->index(['company_id', 'business_sector_id']);
            $table->index(['company_id', 'collection_term_id']);
            $table->index(['company_id', 'payment_term_id']);
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            // Drop new indexes
            $table->dropIndex(['company_id', 'business_sector_id']);
            $table->dropIndex(['company_id', 'collection_term_id']);
            $table->dropIndex(['company_id', 'payment_term_id']);

            // Drop new FKs and columns
            $table->dropConstrainedForeignId('collection_term_id');
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropConstrainedForeignId('business_sector_id');
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropColumn([
                'types',
                'sales_channel',
                'sister_company_name',
                'equity_pct',
            ]);

            // Restore old columns
            $table->string('type', 20)->nullable()->after('code');
            $table->string('payment_terms')->nullable();
            $table->string('customer_category')->nullable();
            $table->decimal('daily_rate', 15, 2)->nullable();
            $table->decimal('hourly_rate', 15, 2)->nullable();
            $table->decimal('shareholding_pct', 5, 2)->nullable();
            $table->date('contribution_date')->nullable();
            $table->string('relationship_type')->nullable();
        });
    }
};
