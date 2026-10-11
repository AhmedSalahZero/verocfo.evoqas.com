<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: recreate_user_module_permissions_table
//
//  Drops the old module-level permission table and recreates it
//  with feature-level granularity.
//
//  Old: one row per user per module (accounting, banking, ...)
//  New: one row per user per module per feature
//       e.g. (user_id=5, module='accounting', feature='customer_invoices')
//
//  Permission flags:
//    can_view    — read / list records
//    can_create  — create new records
//    can_edit    — modify draft / open records
//    can_reverse — void, cancel, or reverse posted records
//                  (replaces can_delete — no hard deletes in Vero CFO)
//
//  Unique key: (company_id, user_id, module, feature)
//  One row per user per feature — maximum granularity.
//
//  @depends  users table, companies table
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Drop old table cleanly
        Schema::dropIfExists('user_module_permissions');

        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── User ──────────────────────────────────────────────
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // ── Module ────────────────────────────────────────────
            // accounting | banking | hr | analytics | planning | valuation
            $table->string('module', 20);

            // ── Feature ───────────────────────────────────────────
            // e.g. customer_invoices | supplier_bills | payroll | etc.
            // See UserModulePermission::FEATURES for full map
            $table->string('feature', 40);

            // ── Permission flags ──────────────────────────────────
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);

            // can_reverse replaces can_delete —
            // controls void / cancel / reverse on posted records
            $table->boolean('can_reverse')->default(false);

            // ── Audit ─────────────────────────────────────────────
            $table->foreignId('granted_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // ── Constraints ───────────────────────────────────────
            $table->unique(
                ['company_id', 'user_id', 'module', 'feature'],
                'ump_company_user_module_feature_unique'
            );

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'user_id']);
            $table->index(['company_id', 'user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};
