<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000321_create_document_sequences_table   (audit L-7)
//
//  One small counter row per company + document type + month.
//  Document codes (CI/2026/10/00001 …) take their number from here
//  instead of "count the existing documents + 1", which could hand
//  the same number to two people at once, repeat a number after a
//  deletion, and followed the month the record was typed rather
//  than the month of the document.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('doc_key', 60);
            $table->string('period', 10);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'doc_key', 'period'], 'doc_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
