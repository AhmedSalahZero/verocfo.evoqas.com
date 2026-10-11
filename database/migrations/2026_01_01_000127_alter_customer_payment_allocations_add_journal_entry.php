<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000127_alter_customer_payment_allocations_add_journal_entry
//
//  Customer Collection v1.1 correction — see
//  VeroCFO_Customer_Collection_Design_v1_1.md Section 1. In v1.0,
//  allocate()/unallocate() were believed to be GL-neutral (both sides
//  supposedly already sat in AR — a re-labeling, not a real
//  transaction). That was the accounting error being corrected: an
//  unapplied receipt sits in 235 Advances from Customers, not AR, so
//  applying it to a real invoice is a genuine account-to-account
//  movement (Dr 235 / Cr AR) that needs its own posted entry —
//  and therefore its own journal_entry_id to reverse when
//  unallocate() is called.
//
//  @layer    Migration
//  @depends  customer_payment_allocations, journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('allocated_amount')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn('journal_entry_id');
        });
    }
};
