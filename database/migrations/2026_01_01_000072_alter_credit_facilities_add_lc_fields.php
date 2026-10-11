<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000072_alter_credit_facilities_add_lc_fields
//
//  Letter of Credit reuses facility_type = 'import_lc' (already in
//  the enum since migration 000043 — no enum change needed) and
//  reuses the existing financing-rate columns from migration 000052
//  (borrowing_rate, bank_margin_rate, interest_rate, min_interest_rate,
//  hdb_rate, follows_corridor_rate) — confirmed with Mahmoud these
//  cover LC's "Financing Terms & Conditions" section exactly, since
//  a bank-funded LC gap is structurally the same revolving,
//  interest-bearing debt as Clean OD's. CBE Corridor propagation
//  already works off follows_corridor_rate alone (not facility_type),
//  so no changes needed there either.
//
//  What's genuinely new for LC:
//    - lc_security_type: Unsecured (Limit typed directly, uses the
//      existing facility_limit column) vs Fully Secured (Limit is
//      COMPUTED = lc_td_amount × lc_td_lending_pct ÷ 100, written
//      into that same facility_limit column rather than a separate
//      one — single source of truth for "the limit", however it
//      was arrived at).
//    - The 4 lc_td_* columns only apply when lc_security_type =
//      'fully_secured' — a pledged Time Deposit as 100% collateral
//      for the whole facility. TD only, no separate CD account type
//      (confirmed) — lc_td_account_id points at the same
//      bank_accounts table, account_type = 'time_deposit'.
//    - lc_td_currency_id is separate from the facility's own
//      currency_id — the pledged TD can be in a different currency
//      than the LC itself, per the reference image showing its own
//      "Select Currency" inside the CD/TD Information section.
//
//  `name` and nullable `coa_account_id` already exist from the LG
//  Agreement build (migrations 000064/000065) and apply here too —
//  no duplicate columns needed.
//
//  @layer    Migration
//  @depends  credit_facilities, bank_accounts, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->enum('lc_security_type', ['unsecured', 'fully_secured'])->nullable()->after('facility_type');

            $table->foreignId('lc_td_account_id')->nullable()->after('lc_security_type')->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('lc_td_currency_id')->nullable()->after('lc_td_account_id')->constrained('currencies')->nullOnDelete();
            $table->decimal('lc_td_amount', 15, 2)->nullable()->after('lc_td_currency_id');
            $table->decimal('lc_td_interest_rate', 6, 4)->nullable()->after('lc_td_amount'); // informational — what the TD itself earns
            $table->decimal('lc_td_lending_pct', 5, 2)->nullable()->after('lc_td_interest_rate'); // the bank's haircut — drives computed Limit
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lc_td_account_id');
            $table->dropConstrainedForeignId('lc_td_currency_id');
            $table->dropColumn(['lc_security_type', 'lc_td_amount', 'lc_td_interest_rate', 'lc_td_lending_pct']);
        });
    }
};
