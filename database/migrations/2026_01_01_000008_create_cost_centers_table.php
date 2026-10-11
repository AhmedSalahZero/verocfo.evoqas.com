<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            $table->string('code', 20);
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // ── Type ──────────────────────────────────────────────────
            // department | branch | project
            $table->string('type', 20)->default('department');

            // ── Hierarchy ─────────────────────────────────────────────
            // Self-referencing — Department → Team structure
            // Null means this is a top-level cost center
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('cost_centers')
                  ->nullOnDelete();

            // ── State ─────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            // Code must be unique per company
            $table->unique(['company_id', 'code']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
