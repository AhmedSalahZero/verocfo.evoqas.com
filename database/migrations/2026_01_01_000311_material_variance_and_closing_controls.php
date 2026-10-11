<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000311_material_variance_and_closing_controls
//
//  Audit M-14 / M-15.
//
//  M-14 — a completed Production/Job Order leaves its MATERIAL
//  variance (actual material consumed minus standard material) sitting
//  in WIP, because completion relieves WIP at standard. The monthly
//  closing now clears it. Each order records which closing cleared it
//  (material_variance_closing_id) and by how much, so an order is
//  never cleared twice and a reversal can put it back.
//
//  M-15 — a posted closing can now be reversed, and a closing that
//  used manual overrides must carry a reason. The old unique index
//  (company, year, month) would block re-closing a reversed period,
//  so it is replaced by one that includes active_key: 1 while the
//  closing stands, NULL once reversed (MySQL allows many NULLs).
//
//  @layer    Migration
//  @depends  production_orders, job_orders, manufacturing_variance_closings
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        foreach (['production_orders', 'job_orders'] as $table) {
            if (! Schema::hasColumn($table, 'material_variance_closing_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('material_variance_closing_id')->nullable()->index();
                    $t->decimal('material_variance_amount', 15, 2)->nullable();
                });
            }
        }

        // Each column is guarded on its own so the migration can be
        // re-run after a partial failure. Foreign keys carry explicit
        // short names (MySQL caps identifiers at 64 characters).
        $table = 'manufacturing_variance_closings';
        $cols = [
            'override_reason'           => fn (Blueprint $t) => $t->text('override_reason')->nullable(),
            'active_key'                => fn (Blueprint $t) => $t->unsignedTinyInteger('active_key')->nullable()->default(1),
            'reversed_at'               => fn (Blueprint $t) => $t->timestamp('reversed_at')->nullable(),
            'reversed_by'               => fn (Blueprint $t) => $t->unsignedBigInteger('reversed_by')->nullable(),
            'reversal_reason'           => fn (Blueprint $t) => $t->text('reversal_reason')->nullable(),
            'reversal_journal_entry_id' => fn (Blueprint $t) => $t->unsignedBigInteger('reversal_journal_entry_id')->nullable(),
        ];
        foreach ($cols as $name => $add) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, $add);
            }
        }
        $fks = ['mvc_reversed_by_fk' => ['reversed_by', 'users'], 'mvc_reversal_je_fk' => ['reversal_journal_entry_id', 'journal_entries']];
        foreach ($fks as $fkName => [$col, $ref]) {
            $exists = collect(Schema::getForeignKeys($table))->contains(fn ($fk) => in_array($col, $fk['columns'], true));
            if (! $exists) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign($col, $fkName)->references('id')->on($ref)->nullOnDelete());
            }
        }

        if (! Schema::hasIndex('manufacturing_variance_closings', 'mfg_variance_closings_active_unique')) {
            Schema::table('manufacturing_variance_closings', function (Blueprint $t) {
                $t->unique(['company_id', 'period_year', 'period_month', 'active_key'], 'mfg_variance_closings_active_unique');
            });
        }
        if (Schema::hasIndex('manufacturing_variance_closings', 'mfg_variance_closings_unique')) {
            Schema::table('manufacturing_variance_closings', function (Blueprint $t) {
                $t->dropUnique('mfg_variance_closings_unique');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: dropping these would orphan reversed closings.
    }
};
