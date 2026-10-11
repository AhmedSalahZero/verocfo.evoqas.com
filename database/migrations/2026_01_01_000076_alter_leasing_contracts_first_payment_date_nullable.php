<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000076_alter_leasing_contracts_first_payment_date_nullable
//
//  First Payment Date removed from the Contract form entirely
//  (confirmed by Mahmoud — redundant once the cheque schedule is
//  entered/uploaded directly). This column was required (NOT NULL)
//  under the old form; without this migration, saving a new contract
//  would fail with a database error the moment the form stopped
//  sending it. The column itself is kept (not dropped) — existing
//  contracts may already have a value in it, and dropping it is a
//  bigger, unnecessary step for what's really just "stop using it."
//
//  @layer    Migration
//  @depends  leasing_contracts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->date('first_payment_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->date('first_payment_date')->nullable(false)->change();
        });
    }
};
