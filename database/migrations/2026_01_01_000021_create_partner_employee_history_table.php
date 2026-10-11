<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000021_create_partner_employee_history_table
//
//  Tracks changes to department and job_title on Employee-type
//  partners over time. Append-only — records are never updated
//  or deleted.
//
//  A new row is inserted by PartnerService whenever department
//  or job_title changes on an Employee partner. The service
//  compares old vs new value and only writes if there is
//  an actual change.
//
//  effective_date: the date the change took effect (user-entered,
//  defaults to today but can be backdated for historical imports).
//
//  changed_by: the user_id who made the change — always set.
//
//  @layer    Migration
//  @depends  partners, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_employee_history', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Employee Partner ──────────────────────────────────
            $table->foreignId('partner_id')
                  ->constrained('partners')
                  ->cascadeOnDelete();

            // ── What Changed ──────────────────────────────────────
            // field: 'department' | 'job_title' | 'cost_center_id'
            $table->string('field', 30);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            // ── When ──────────────────────────────────────────────
            // effective_date: when the change took effect (user-set)
            $table->date('effective_date');

            // ── Who ───────────────────────────────────────────────
            $table->foreignId('changed_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            // ── Timestamps ────────────────────────────────────────
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'partner_id', 'field']);
            $table->index(['company_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_employee_history');
    }
};
