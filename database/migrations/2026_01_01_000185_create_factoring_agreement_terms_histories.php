<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000185_create_factoring_agreement_terms_histories
//
//  Factoring Agreement Renewal — same "dated chapter timeline"
//  design already used for OD-family facilities (see
//  CreditFacilityTermsHistory / migration 000134), rebuilt against
//  Factoring's own field set.
//
//  ODA equivalent            →  Factoring equivalent
//  ─────────────────────────────────────────────────────────────
//  review_date                → end_date (agreement expiry)
//  borrowing_rate              → borrowing_rate
//  bank_margin_rate            → discount_margin_rate
//  interest_rate (auto)        → discount_rate (auto)
//  follows_corridor_rate       → follows_corridor_rate (unchanged concept)
//
//  The first chapter (is_original = true) is created the moment the
//  agreement itself is created. Every Renew after that adds one more
//  chapter, dated to its own effective_date — the agreement's own
//  end_date / borrowing_rate / discount_margin_rate / discount_rate
//  columns always mirror whichever chapter is currently live, same
//  as CreditFacility does for OD facilities.
//
//  @layer    Migration
//  @depends  factoring_agreements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factoring_agreement_terms_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('factoring_agreement_id');

            $table->date('effective_date');
            $table->date('end_date');

            $table->decimal('borrowing_rate', 8, 4)->default(0);
            $table->decimal('discount_margin_rate', 8, 4)->default(0);
            $table->decimal('discount_rate', 8, 4)->default(0);   // borrowing + margin, stored for history
            $table->integer('extended_payment_days')->nullable(); // reverse agreements only

            $table->boolean('is_original')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // NOTE: the auto-generated FK name (table_column_foreign)
            // would be 66 characters — over MySQL's 64-char identifier
            // limit — since both the table and column names are long.
            // Named explicitly and kept short instead.
            $table->foreign('factoring_agreement_id', 'fatp_agreement_id_fk')
                  ->references('id')->on('factoring_agreements')->cascadeOnDelete();
            $table->index(['company_id', 'factoring_agreement_id'], 'fatp_company_agreement_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('factoring_agreement_terms_histories'); }
};
