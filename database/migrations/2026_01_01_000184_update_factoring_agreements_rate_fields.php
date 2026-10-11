<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000184_update_factoring_agreements_rate_fields
//
//  Redesign of the Factoring Agreement Terms & Accounts section:
//    - bank_account_id removed — a factoring company can settle
//      proceeds into whichever bank account it chooses per invoice,
//      so pinning one account to the agreement had no real logic
//      behind it.
//    - min_invoice_age_days / min_invoice_amount removed — these
//      were unused eligibility constraints.
//    - borrowing_rate + discount_margin_rate added, mirroring the
//      Borrowing Rate / Bank Margin Rate pattern already used on
//      Overdraft facilities. discount_rate is kept as the stored,
//      auto-calculated total (Borrowing Rate + Discount Margin Rate).
//    - follows_corridor_rate added — same "Link to CBE Corridor
//      Rate" toggle used on Overdraft facilities.
//
//  @layer    Migration
//  @depends  factoring_agreements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factoring_agreements', function (Blueprint $table) {
            $table->dropColumn(['bank_account_id', 'min_invoice_age_days', 'min_invoice_amount']);
            $table->decimal('borrowing_rate', 8, 4)->default(0)->after('recourse_type');
            $table->decimal('discount_margin_rate', 8, 4)->default(0)->after('borrowing_rate');
            $table->boolean('follows_corridor_rate')->default(false)->after('discount_rate');
        });
    }

    public function down(): void
    {
        Schema::table('factoring_agreements', function (Blueprint $table) {
            $table->dropColumn(['borrowing_rate', 'discount_margin_rate', 'follows_corridor_rate']);
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->integer('min_invoice_age_days')->nullable();
            $table->decimal('min_invoice_amount', 15, 2)->nullable();
        });
    }
};
