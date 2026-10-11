<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000026_alter_currencies_nullable_company_id
//
//  Makes company_id nullable on the currencies table so system
//  currencies (EGP, USD, EUR, etc.) can be seeded with
//  company_id = NULL and shared across all companies.
//
//  Same pattern as chart_of_accounts and payment_terms.
//
//  The unique constraint on (company_id, code) already allows
//  multiple NULLs in MySQL — no change needed there.
//
//  @layer    Migration
//  @depends  currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable(false)
                  ->change();
        });
    }
};
