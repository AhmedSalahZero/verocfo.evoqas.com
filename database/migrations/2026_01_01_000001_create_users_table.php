<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ── Identity ──────────────────────────────────────────────
            $table->string('name');                          // English full name
            $table->string('name_ar')->nullable();           // Arabic full name
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // ── Company relationship ──────────────────────────────────
            // Nullable — Super Admin is not bound to any company
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->nullOnDelete();

            // ── Role ─────────────────────────────────────────────────
            // Three levels: super_admin | company_admin | user
            // Closed set — string column is appropriate here
            $table->string('role', 20)->default('user');

            // ── Profile ───────────────────────────────────────────────
            $table->string('job_title_en')->nullable();
            $table->string('job_title_ar')->nullable();

            // ── Preferences ───────────────────────────────────────────
            // lang_pref: 'en' or 'ar' — drives locale in app.js
            $table->string('lang_pref', 2)->default('en');
            $table->string('timezone', 50)->default('Africa/Cairo');

            // ── Access control ────────────────────────────────────────
            // Deactivated users lose all access immediately — data preserved
            $table->boolean('is_active')->default(true)->index();

            // ── Audit ─────────────────────────────────────────────────
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'role']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};