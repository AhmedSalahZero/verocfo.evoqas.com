<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: create_user_module_permissions_table
//
//  Custom permission matrix table for Vero CFO.
//  One row per user per module — four boolean flags.
//
//  Why custom instead of Spatie permissions:
//    - Maps exactly to our four-flag system (view/create/edit/delete)
//    - Simpler to query in permission checks
//    - Full audit trail via granted_by + timestamps
//    - Spatie still handles roles (company_admin / user)
//
//  Module keys: accounting | banking | analytics | planning | valuation
//
//  The UNIQUE constraint on (company_id, user_id, module) ensures
//  one permission row per user per module — upsert pattern used
//  in UserService when saving permissions.
//
//  @depends  users table, companies table
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
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
            // accounting | banking | analytics | planning | valuation
            $table->string('module', 20);

            // ── Permission flags ──────────────────────────────────
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);

            // ── Audit ─────────────────────────────────────────────
            // Who granted / last updated these permissions
            $table->foreignId('granted_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // ── Constraints ───────────────────────────────────────
            // One permission row per user per module per company
            $table->unique(['company_id', 'user_id', 'module'], 'ump_company_user_module_unique');

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'user_id']);
            $table->index(['company_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};
