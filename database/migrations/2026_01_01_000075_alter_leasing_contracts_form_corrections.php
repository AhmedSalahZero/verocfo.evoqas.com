<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000075_alter_leasing_contracts_form_corrections
//
//  Confirmed corrections to the Leasing Contract form:
//
//    - entry_type ('new' | 'existing') — NEW concept, distinct from
//      the pre-existing `contract_type` column (operational/capital
//      lease classification, unchanged). Drives whether this
//      contract triggers a liability-recognition posting at all:
//      'existing' → no action (the lease predates Vero CFO, same
//      principle as Opening Balances / LG-LC "Opening Issuance").
//      'new' → a real event — see ContractForm.vue header comment
//      for the full pending-GL-posting plan once the Accounting
//      Engine exists.
//
//    - installment_interval (monthly/quarterly/semi_annual/annual) —
//      new. monthly_payment renamed to installment_amount to match
//      (a lease with a quarterly interval isn't a "monthly" payment
//      even if the amount column kept that name).
//
//    - bank_liability_account_id — NEW field, a single Chart of
//      Accounts liability account. IMPORTANT — flagged to Mahmoud,
//      not decided here: this table ALREADY has
//      lease_liability_lt_account_id and lease_liability_current_
//      account_id sitting dormant (fillable, never wired into any
//      form). Those look like they may have been intended for
//      exactly this purpose (an IFRS16-style long-term/current
//      liability split) but nothing currently uses them. This
//      migration adds bank_liability_account_id as a genuinely NEW,
//      separate column rather than silently repurposing either
//      dormant one — confirm with Mahmoud whether the new field
//      should actually replace them instead.
//
//  @layer    Migration
//  @depends  leasing_contracts, lease_cheques, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->enum('entry_type', ['new', 'existing'])->default('new')->after('contract_type');
            $table->enum('installment_interval', ['monthly', 'quarterly', 'semi_annual', 'annual'])->default('monthly')->after('total_contract_value');
            $table->foreignId('bank_liability_account_id')->nullable()->after('bank_account_id')->constrained('chart_of_accounts')->nullOnDelete();
        });

        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->renameColumn('monthly_payment', 'installment_amount');
        });

        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->decimal('beginning_balance', 15, 2)->nullable()->after('payment_date');
            $table->decimal('end_balance', 15, 2)->nullable()->after('interest_amount');
        });
    }

    public function down(): void
    {
        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->dropColumn(['beginning_balance', 'end_balance']);
        });

        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->renameColumn('installment_amount', 'monthly_payment');
        });

        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_liability_account_id');
            $table->dropColumn(['entry_type', 'installment_interval']);
        });
    }
};
