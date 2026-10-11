<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000303 — fixed asset disposal, impairment and transfer
//
//  AUDIT H17 (Sept 2026). Fixed assets could only be bought and
//  depreciated. The 'disposed' status existed on fixed_assets
//  (migration 000168) but nothing could set it. This adds:
//
//  fixed_asset_disposals — sale or scrapping (IAS 16.67–72).
//    Confirmed with Mahmoud: a FULL month of depreciation is charged
//    in the month of disposal (mirrors "depreciation starts the month
//    after receipt"). The snapshot columns (cost, accumulated
//    depreciation, net book value, gain / loss) record exactly what
//    was taken off the books. previous_status lets a cancelled
//    disposal put the asset back exactly as it was.
//
//  fixed_asset_impairments — IAS 36 write-down of the carrying amount.
//    Posted Dr 546 Impairment Loss / Cr the asset's accumulated
//    depreciation account ("accumulated depreciation and impairment").
//    fixed_assets.accumulated_impairment keeps the running total so
//    later depreciation is spread over what is left.
//
//  fixed_asset_transfers — a move between locations. No ledger effect
//    (one shared GL account per category); an audit trail only.
//
//  No hard deletes: disposals and impairments are cancelled (their
//  journal entries reversed), never removed.
//
//  @layer    Migration
//  @depends  fixed_assets, chart_of_accounts, partners, journal_entries,
//            fixed_asset_depreciation_entries, inventory_locations, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->decimal('accumulated_impairment', 15, 2)->default(0)->after('accumulated_depreciation');
        });

        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->date('disposal_date');
            $table->enum('disposal_type', ['sale', 'scrap']);
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete(); // buyer (sale)
            $table->decimal('proceeds', 15, 2)->default(0);       // excluding VAT
            $table->decimal('vat_amount', 15, 2)->default(0);     // Cr 226001 VAT Output
            $table->foreignId('proceeds_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->decimal('cost_basis', 15, 2);
            $table->decimal('accumulated_depreciation', 15, 2);   // incl. accumulated impairment
            $table->decimal('net_book_value', 15, 2);
            $table->decimal('gain_loss', 15, 2);                  // + gain (424) / − loss (545)
            $table->string('previous_status', 20);
            $table->foreignId('depreciation_entry_id')->nullable()->constrained('fixed_asset_depreciation_entries')->nullOnDelete();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id']);
        });

        Schema::create('fixed_asset_impairments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->date('impairment_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('carrying_amount_before', 15, 2);
            $table->text('reason');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id']);
        });

        Schema::create('fixed_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->date('transfer_date');
            $table->foreignId('from_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->constrained('inventory_locations');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfers');
        Schema::dropIfExists('fixed_asset_impairments');
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::table('fixed_assets', fn (Blueprint $t) => $t->dropColumn('accumulated_impairment'));
    }
};
