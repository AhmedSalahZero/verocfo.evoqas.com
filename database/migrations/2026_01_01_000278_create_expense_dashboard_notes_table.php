<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_expense_dashboard_notes_table
//
//  Analyst Notes on the Analytics > Expenses > Dashboard page — same
//  role and shape as sales_dashboard_notes (see that migration for
//  full context), kept as its own table rather than a shared
//  polymorphic one so each dashboard's notes stay simple to query
//  and there's no risk of a sales note ever showing up on the
//  expense page or vice versa.
//
//  @layer    Migration
//  @depends  companies, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_dashboard_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->longText('note');
            $table->timestamps();

            $table->index(['company_id', 'date_from', 'date_to'], 'edn_company_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_dashboard_notes');
    }
};
