<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000134_create_credit_facility_terms_histories_table
//
//  Facility Renewal — Phase 1 (OD family: Clean OD, CP OD,
//  Contract-Assignment OD). Ported from CashVero's proven
//  "terms history / chapters" design (studied August 2026), rebuilt
//  for Vero CFO's architecture — one shared table across every
//  facility type instead of CashVero's one-table-per-type approach,
//  since credit_facilities is already unified the same way.
//
//  Every OD-family facility gets a dated timeline of terms ("chapters")
//  instead of one static snapshot: one row per chapter (original
//  contract + every renewal after it), each with the date it took
//  effect. getTermsAsOfDate() on CreditFacility is the single source
//  of truth for "what were this facility's terms on a given date" —
//  used once the Settlement Engine exists so a transaction dated
//  before a renewal correctly resolves against the OLD terms, and
//  used today by the Renew screen to show current values. This is a
//  PHP model method, never a database trigger — CashVero's original
//  used a MySQL trigger for this; that mechanism was explicitly
//  rejected per the CashVero gap-analysis conclusion (invisible to
//  audit_log, can't throw typed exceptions, bypasses service-first
//  architecture).
//
//  Fields mirror the five renewal-controlled columns already on
//  credit_facilities for the OD family, PLUS borrowing_rate and
//  bank_margin_rate (confirmed with Mahmoud — Vero CFO's renewal
//  covers these two as well, unlike CashVero's Clean OD renewal
//  which deliberately excludes them in favor of its separate
//  "Rates" history mechanism; Vero CFO's equivalent of that separate
//  mechanism is the already-built CBE Corridor Rate cascade, which
//  still owns borrowing_rate whenever follows_corridor_rate = true —
//  see RenewCleanOverdraftRequest for how that interaction is
//  enforced).
//
//  Design decision (confirmed with Mahmoud, differs from CashVero):
//  Edit is NEVER locked by the existence of a renewal. Edit always
//  corrects whichever chapter is CURRENTLY live (the Original if
//  never renewed, or the latest renewal otherwise) IN PLACE — no new
//  date, no new row. Renew is the only action that ever inserts a
//  new dated chapter. This matches CashVero's own final, client-
//  corrected behavior (its first design locked Edit after any
//  renewal; that was superseded on the same day it was built).
//
//  Deletion rule (confirmed with Mahmoud): a facility still on its
//  Original chapter (never renewed) can be deleted outright, as long
//  as no real money has moved through it. Once renewed, the facility
//  itself cannot be deleted — only its most recent renewal can be
//  undone (reverting to the previous chapter), and only if nothing
//  real is dated on/after that renewal's effective date. This mirrors
//  CashVero's deleteLatestRenewal() rule exactly.
//
//  Nullable columns: a renewal only has to state what actually
//  changed — anything left blank carries forward from the previous
//  chapter (see CreditFacility::renew()). review_date is the one
//  exception, always required on a renewal (there is no sensible
//  "carry forward" for a date that must, by definition, move forward).
//
//  is_original: true only for the row auto-created from the
//  facility's own creation — never itself a renewal. Lets the UI
//  show "Original Terms" vs "Renewal" without guessing, and is what
//  hasRenewals() / deleteLatestRenewal() key off.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_facility_terms_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('credit_facility_id');
            $table->date('effective_date');

            $table->decimal('facility_limit', 15, 2)->nullable();
            $table->decimal('borrowing_rate', 8, 4)->nullable();
            $table->decimal('bank_margin_rate', 8, 4)->nullable();
            $table->decimal('hdb_rate', 8, 4)->nullable();
            $table->decimal('admin_fee_rate', 8, 4)->nullable();
            $table->integer('settled_max_days')->nullable();

            // Type-specific optional caps — only ever populated for the
            // facility_type that actually uses them (CP OD / Contract-
            // Assignment OD respectively). NULL for Clean OD chapters.
            $table->decimal('max_lending_limit_per_customer', 15, 2)->nullable();
            $table->decimal('max_lending_limit_per_contract', 15, 2)->nullable();

            $table->date('review_date')->nullable();  // required on every actual renewal; nullable here only for the Original backfill row's own safety
            $table->text('notes')->nullable();

            $table->boolean('is_original')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('credit_facility_id')
                  ->references('id')->on('credit_facilities')
                  ->cascadeOnDelete();

            $table->index(['company_id', 'credit_facility_id'], 'cfth_company_facility_idx');
            $table->index(['credit_facility_id', 'effective_date'], 'cfth_facility_date_idx');

            // A facility can only have one chapter taking effect on any
            // given date — mirrors CashVero's "no duplicate renewal
            // dates" rule.
            $table->unique(['credit_facility_id', 'effective_date'], 'cfth_facility_date_unique');
        });

        // Backfill: give every existing OD-family facility its "chapter
        // one" row, built from its current field values, dated to its
        // start_date. Without this, a facility renewed before ever
        // getting a backfilled row would have no baseline chapter to
        // revert to. Scoped to the three OD types this feature covers
        // in its first pass (Clean OD / CP OD / Contract-Assignment OD)
        // — LG/LC facility renewal is a later phase, confirmed with
        // Mahmoud, and will get its own backfill when it's built.
        $rows = DB::table('credit_facilities')
            ->whereIn('facility_type', ['clean_od', 'commercial_paper', 'contract_so'])
            ->select(
                'id', 'company_id', 'start_date', 'review_date',
                'facility_limit', 'borrowing_rate', 'bank_margin_rate',
                'hdb_rate', 'admin_fee_rate', 'settled_max_days',
                'max_lending_limit_per_customer', 'max_lending_limit_per_contract'
            )->get();

        $now = now();
        foreach ($rows as $row) {
            DB::table('credit_facility_terms_histories')->insert([
                'company_id'                     => $row->company_id,
                'credit_facility_id'              => $row->id,
                'effective_date'                   => $row->start_date ?: '2000-01-01',
                'facility_limit'                   => $row->facility_limit ?: 0,
                'borrowing_rate'                   => $row->borrowing_rate ?: 0,
                'bank_margin_rate'                 => $row->bank_margin_rate ?: 0,
                'hdb_rate'                          => $row->hdb_rate ?: 0,
                'admin_fee_rate'                    => $row->admin_fee_rate ?: 0,
                'settled_max_days'                  => $row->settled_max_days,
                'max_lending_limit_per_customer'    => $row->max_lending_limit_per_customer,
                'max_lending_limit_per_contract'    => $row->max_lending_limit_per_contract,
                'review_date'                       => $row->review_date,
                'is_original'                       => true,
                'notes'                              => 'Backfilled from original facility terms.',
                'created_at'                         => $now,
                'updated_at'                         => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_facility_terms_histories');
    }
};
