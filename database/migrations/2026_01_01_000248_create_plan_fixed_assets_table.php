<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000248_create_plan_fixed_assets_table
//
//  Confirmed simple, two-source design (Sept 2026, spec Section 3.4)
//  — exactly two ways a row gets here, no third option:
//
//    fixed_asset_id set   → a real, already-existing FixedAsset the
//      user picked from the company's real register. cost_basis /
//      residual_value / useful_life_months / depreciation_start_date
//      are copied in at the moment the plan is built (same
//      point-in-time snapshot principle as plan_products) so the plan
//      keeps working even if the real asset is later edited. Its
//      REMAINING depreciation is projected forward using the exact
//      same straight-line formula FixedAsset::monthlyDepreciationAmount()
//      already uses — (cost_basis − residual_value) ÷
//      useful_life_months — so the numbers agree with what the real
//      asset actually posts.
//    fixed_asset_id NULL  → a planned future purchase that doesn't
//      exist in the real register yet (e.g. "a new machine in Year
//      2"). purchase_month is 0-based, same grain as
//      plan_sales_lines.period_month. Depreciation starts the month
//      after purchase_month, same convention as the real
//      FixedAsset.depreciation_start_date default.
//
//  No construction-in-progress phase, no S-curve billing schedule, no
//  equity/debt funding split — deliberately not built, per the
//  confirmed instruction to keep this simple and matching how the
//  real Fixed Assets module already works (straight-line only,
//  Phase 1 — FixedAsset::METHOD_STRAIGHT_LINE).
//
//  @layer    Migration
//  @depends  plans, fixed_assets
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // Nullable — see class header. Set = a real existing
            // asset; null = a planned future purchase.
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();

            $table->string('name', 200);
            $table->decimal('cost_basis', 15, 2)->default(0);
            $table->decimal('residual_value', 15, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months')->default(1);

            // Snapshot for an existing asset (how much it has already
            // depreciated, so the plan projects only what's left).
            // Always 0 for a planned future purchase.
            $table->decimal('accumulated_depreciation_snapshot', 15, 2)->default(0);

            // Planned future purchase only — when the plan assumes
            // the cash goes out. NULL for an existing asset (it was
            // already purchased, before the plan started).
            $table->unsignedSmallInteger('purchase_month')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_fixed_assets');
    }
};
