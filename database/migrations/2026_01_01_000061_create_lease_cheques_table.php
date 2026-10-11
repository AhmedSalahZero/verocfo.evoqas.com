<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000061_create_lease_cheques_table
//
//  Manually-entered real cheque schedule handed over to the leasing
//  company — applies to EVERY contract type (Treatment A, Treatment B,
//  and Capital alike).
//
//  This is intentionally separate from lease_amortization_lines:
//    - lease_amortization_lines is auto-calculated by the system via
//      bcmath present-value formulas (Rule 29.2), only for Treatment B
//      / Capital, and drives the ROU/lease-liability journal entries.
//      That logic is UNCHANGED by this table.
//    - lease_cheques is the real, physical record of what cheques were
//      actually handed to the leasing company, with the real principal/
//      interest breakdown as documented by the leasing company itself.
//      The two schedules may differ slightly — that is expected, since
//      this table exists to record reality, not to recalculate it.
//
//  status / withdrawn_date are forward-looking fields for a future
//  Accounting Engine feature: marking a cheque as withdrawn from the
//  bank on a specific date, for accurate bank statement reconciliation.
//  Not used by any logic yet.
//
//  @layer    Migration
//  @depends  leasing_contracts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_cheques', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('leasing_contract_id')
                  ->constrained('leasing_contracts')
                  ->cascadeOnDelete();

            $table->date('payment_date');
            $table->string('cheque_number', 50);
            $table->decimal('cheque_amount', 15, 2);
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2);

            // Forward-looking — not used yet, see header comment
            $table->enum('status', ['pending', 'withdrawn', 'bounced'])->default('pending');
            $table->date('withdrawn_date')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id', 'leasing_contract_id'], 'lc_company_contract');
            $table->index(['company_id', 'leasing_contract_id', 'payment_date'], 'lc_company_contract_date');
            $table->index(['company_id', 'leasing_contract_id', 'status'], 'lc_company_contract_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_cheques');
    }
};
