<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Migration: alter_companies_company_type_to_json
//
//  Changes company_type from varchar(30) to JSON array.
//
//  Old: company_type = "Manufacturing"  (single string)
//  New: company_type = ["Trading","Manufacturing"]  (JSON array)
//
//  Valid values:
//    Service | Trading | Manufacturing | Service with Inventory
//
//  Existing single-value rows are converted to single-item arrays.
//  "Mixed" and "System Integrator" are mapped to their equivalents.
//
//  Three helper methods on Company model read this:
//    hasInventory()      → Trading | Manufacturing | Service with Inventory
//    isManufacturing()   → Manufacturing only
//    hasMixedInvoices()  → Service with Inventory only
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — Add new JSON column alongside old one
        Schema::table('companies', function (Blueprint $table) {
            $table->json('company_types')->nullable()->after('company_type');
        });

        // Step 2 — Migrate existing data
        DB::table('companies')->orderBy('id')->each(function ($company) {
            $old = $company->company_type;

            // Map old values to new array format
            $new = match ($old) {
                'Manufacturing'     => ['Manufacturing'],
                'Trading'           => ['Trading'],
                'Services',
                'Service'           => ['Service'],
                'Mixed'             => ['Trading', 'Manufacturing'],
                'System Integrator' => ['Service with Inventory'],
                default             => ['Service'],
            };

            DB::table('companies')
                ->where('id', $company->id)
                ->update(['company_types' => json_encode($new)]);
        });

        // Step 3 — Drop old column and rename new one
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('company_type');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('company_types', 'company_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_type_old', 30)->nullable()->after('company_type');
        });

        DB::table('companies')->orderBy('id')->each(function ($company) {
            $types = json_decode($company->company_type, true) ?? [];
            $old   = count($types) > 1 ? 'Mixed' : ($types[0] ?? 'Service');

            DB::table('companies')
                ->where('id', $company->id)
                ->update(['company_type_old' => $old]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('company_type');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('company_type_old', 'company_type');
        });
    }
};
