<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            // Nullable — Super Admin actions are not company-scoped
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->nullOnDelete();

            // ── Actor ─────────────────────────────────────────────────
            // Nullable — system jobs have no user
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Super Admin impersonation — who was impersonating when this was logged
            $table->foreignId('impersonated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ── Action ────────────────────────────────────────────────
            // e.g. created | updated | deleted | posted | closed | job_failed
            $table->string('action', 30);

            // ── Subject ───────────────────────────────────────────────
            // The model class and ID that was affected
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id')->nullable();

            // ── Payload ───────────────────────────────────────────────
            // Before and after state — JSON
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // ── Context ───────────────────────────────────────────────
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            // ── Timestamp ─────────────────────────────────────────────
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'action']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
            $table->index(['company_id', 'user_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
