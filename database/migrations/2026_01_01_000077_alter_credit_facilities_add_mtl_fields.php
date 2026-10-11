<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000077_alter_credit_facilities_add_mtl_fields
//
//  Medium Term Loan reuses facility_type = 'mtl' (already in the
//  enum since migration 000043) and reuses most of its fields
//  directly from credit_facilities — no new columns needed for
//  Name, Start Date, Currency, Limit (facility_limit), Account
//  Number, Borrowing/Margin/Interest Rate, or Bank. "End Date" maps
//  to the existing `review_date` column, same convention as every
//  other facility type. "Duration (Months)" maps to the existing
//  (previously unused) `tenure_months` column.
//
//  Genuinely new for MTL:
//    - entry_type ('new' | 'existing') — same role and same values
//      as Leasing's entry_type (migration 000075). 'existing' → no
//      posting ever. 'new' → real event, needs a pending-GL-posting
//      entry for the initial loan liability once the Accounting
//      Engine exists (see MtlService for the exact comment).
//    - installment_interval (monthly/quarterly/semi_annual/annual).
//
//  NOT wired into this migration, flagged to Mahmoud rather than
//  silently resolved: `principal_amount` and `first_payment_date`
//  already exist on this table (added speculatively in an earlier
//  session) but are NOT used by MTL as built here — facility_limit
//  already serves the "principal_amount" role, and per Mahmoud's
//  confirmed decision on Leasing (no first payment date — the
//  schedule is entered/uploaded directly, nothing left for a
//  separate first-payment-date field to represent), the same logic
//  applies to MTL. Left dormant rather than dropped.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->enum('entry_type', ['new', 'existing'])->nullable()->after('facility_type');
            $table->enum('installment_interval', ['monthly', 'quarterly', 'semi_annual', 'annual'])->nullable()->after('facility_limit');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn(['entry_type', 'installment_interval']);
        });
    }
};
