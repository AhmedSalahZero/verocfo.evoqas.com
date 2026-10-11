<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->nullOnDelete();

            // ── Recipient ─────────────────────────────────────────────
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // ── Content ───────────────────────────────────────────────
            // type: info | warning | error | success
            $table->string('type', 20)->default('info');

            // severity: low | medium | high
            // error notifications from failed jobs are always high
            $table->string('severity', 10)->default('low');

            $table->string('title');
            $table->text('message');

            // Optional link to the relevant record
            $table->string('action_url')->nullable();
            $table->string('action_label')->nullable();

            // ── Source ────────────────────────────────────────────────
            // What generated this notification — job class, event, or system
            $table->string('source', 100)->nullable();

            // ── State ─────────────────────────────────────────────────
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'user_id', 'is_read']);
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['company_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
