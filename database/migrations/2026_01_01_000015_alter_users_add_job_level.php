<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: alter_users_add_job_level
//
//  Adds job_level to the users table.
//
//  job_level is organizational seniority — purely informational.
//  It does NOT affect system access or permissions in any way.
//  Access is controlled by role + user_module_permissions.
//
//  Values: shareholder | director | manager | senior | junior | staff
//
//  Nullable — existing users are not forced to have a level set.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_level', 20)
                  ->nullable()
                  ->after('job_title_ar');

            $table->index(['company_id', 'job_level']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'job_level']);
            $table->dropColumn('job_level');
        });
    }
};
