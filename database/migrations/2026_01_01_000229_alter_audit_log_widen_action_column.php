<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000229_alter_audit_log_widen_action_column
//
//  audit_log.action was created as varchar(30) (migration 000002),
//  back when the intended values were short single words: created |
//  updated | deleted | posted | closed | job_failed.
//
//  In practice, every service instead calls
//  AuditLog::record($actor, 'module_name.action', ...) — a
//  "model_name.verb" convention (e.g. 'employee_payroll_profile.
//  created', 'supplier_payment.original_allocation_released').
//  As the app grew, several of these strings passed 30 characters
//  and started silently truncating — MySQL only throws in strict
//  mode, which is why this surfaced now as a QueryException instead
//  of quietly cutting the value short earlier.
//
//  An audit of every AuditLog::record() call in app/Services found
//  19 action strings already over 30 chars, the longest at 45
//  ('supplier_payment.original_allocation_released'). Widening to
//  100 (matching auditable_type's own column width) fixes all of
//  today's call sites and leaves headroom for future ones, instead
//  of hand-shortening dozens of action names across the codebase —
//  which would also make the audit trail's action names less
//  readable and inconsistent with each other.
//
//  @layer    Migration
//  @depends  audit_log
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('action', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('action', 30)->change();
        });
    }
};
