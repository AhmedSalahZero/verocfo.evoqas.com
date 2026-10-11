<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000319_add_missing_foreign_keys   (audit M-37)
//
//  The first tables (projects, sales orders, banks, bank accounts,
//  credit facilities, leasing, factoring, facility balances) were
//  created with plain number columns and no foreign keys, so a row
//  could point at something that does not exist. This adds the keys.
//
//  Safe by design:
//   * a key that already exists is left alone;
//   * a table or column that is not there is skipped;
//   * if a column already holds values with no matching parent row
//     (an orphan), that one key is SKIPPED and written to the log —
//     data is never deleted or changed here. Fix the orphans, then
//     re-run this migration's rows by hand.
//
//  @layer    Migration
//  @depends  the tables listed below
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    /** [table, column, parent table, on delete] */
    private array $keys = [
            ['projects', 'company_id', 'companies', 'cascade'],
            ['projects', 'partner_id', 'partners', 'restrict'],
            ['projects', 'currency_id', 'currencies', 'restrict'],
            ['projects', 'project_site_location_id', 'inventory_locations', 'set null'],
            ['projects', 'created_by', 'users', 'restrict'],
            ['projects', 'updated_by', 'users', 'set null'],
            ['sales_orders', 'company_id', 'companies', 'cascade'],
            ['sales_orders', 'project_id', 'projects', 'restrict'],
            ['sales_orders', 'partner_id', 'partners', 'restrict'],
            ['sales_orders', 'currency_id', 'currencies', 'restrict'],
            ['sales_orders', 'payment_term_id', 'payment_terms', 'set null'],
            ['sales_orders', 'created_by', 'users', 'restrict'],
            ['sales_orders', 'updated_by', 'users', 'set null'],
            ['sales_order_lines', 'company_id', 'companies', 'cascade'],
            ['sales_order_lines', 'sales_order_id', 'sales_orders', 'cascade'],
            ['sales_order_lines', 'product_id', 'products', 'restrict'],
            ['sales_order_lines', 'uom_id', 'units_of_measure', 'restrict'],
            ['sales_order_lines', 'revenue_account_id', 'chart_of_accounts', 'restrict'],
            ['sales_order_lines', 'cost_center_id', 'cost_centers', 'set null'],
            ['banks', 'company_id', 'companies', 'cascade'],
            ['banks', 'created_by', 'users', 'restrict'],
            ['bank_accounts', 'company_id', 'companies', 'cascade'],
            ['bank_accounts', 'bank_id', 'banks', 'restrict'],
            ['bank_accounts', 'currency_id', 'currencies', 'restrict'],
            ['bank_accounts', 'coa_account_id', 'chart_of_accounts', 'restrict'],
            ['bank_accounts', 'linked_facility_id', 'credit_facilities', 'set null'],
            ['bank_accounts', 'created_by', 'users', 'restrict'],
            ['credit_facilities', 'company_id', 'companies', 'cascade'],
            ['credit_facilities', 'bank_id', 'banks', 'restrict'],
            ['credit_facilities', 'coa_account_id', 'chart_of_accounts', 'restrict'],
            ['credit_facilities', 'bank_account_id', 'bank_accounts', 'set null'],
            ['credit_facilities', 'currency_id', 'currencies', 'restrict'],
            ['credit_facilities', 'collateral_project_id', 'projects', 'set null'],
            ['credit_facilities', 'collateral_so_id', 'sales_orders', 'set null'],
            ['credit_facilities', 'lg_cash_cover_account_id', 'chart_of_accounts', 'set null'],
            ['credit_facilities', 'lc_supplier_id', 'partners', 'set null'],
            ['credit_facilities', 'lc_cash_cover_account_id', 'chart_of_accounts', 'set null'],
            ['credit_facilities', 'created_by', 'users', 'restrict'],
            ['leasing_companies', 'company_id', 'companies', 'cascade'],
            ['leasing_companies', 'created_by', 'users', 'restrict'],
            ['leasing_contracts', 'company_id', 'companies', 'cascade'],
            ['leasing_contracts', 'leasing_company_id', 'leasing_companies', 'restrict'],
            ['leasing_contracts', 'expense_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'rou_asset_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'rou_amort_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'fixed_asset_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'lease_liability_lt_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'lease_liability_current_account_id', 'chart_of_accounts', 'set null'],
            ['leasing_contracts', 'bank_account_id', 'bank_accounts', 'restrict'],
            ['leasing_contracts', 'created_by', 'users', 'restrict'],
            ['lease_amortization_lines', 'company_id', 'companies', 'cascade'],
            ['lease_amortization_lines', 'leasing_contract_id', 'leasing_contracts', 'cascade'],
            ['lease_amortization_lines', 'journal_entry_id', 'journal_entries', 'set null'],
            ['factoring_companies', 'company_id', 'companies', 'cascade'],
            ['factoring_companies', 'created_by', 'users', 'restrict'],
            ['factoring_agreements', 'company_id', 'companies', 'cascade'],
            ['factoring_agreements', 'factoring_company_id', 'factoring_companies', 'restrict'],
            ['factoring_agreements', 'coa_account_id', 'chart_of_accounts', 'restrict'],
            ['factoring_agreements', 'bank_account_id', 'bank_accounts', 'restrict'],
            ['factoring_agreements', 'eligible_partner_id', 'partners', 'set null'],
            ['factoring_agreements', 'created_by', 'users', 'restrict'],
            ['facility_withdrawals', 'company_id', 'companies', 'cascade'],
            ['facility_withdrawals', 'credit_facility_id', 'credit_facilities', 'restrict'],
            ['facility_withdrawals', 'journal_entry_id', 'journal_entries', 'set null'],
            ['facility_liability_charges', 'company_id', 'companies', 'cascade'],
            ['facility_liability_charges', 'credit_facility_id', 'credit_facilities', 'restrict'],
            ['facility_liability_charges', 'journal_entry_id', 'journal_entries', 'set null'],
            ['facility_liability_charges', 'posted_by', 'users', 'set null'],
            ['facility_daily_balances', 'company_id', 'companies', 'cascade'],
            ['facility_daily_balances', 'credit_facility_id', 'credit_facilities', 'cascade'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->keys as [$table, $column, $parent, $onDelete]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($parent)) {
                continue;
            }
            $name = substr("fk_{$table}_{$column}", 0, 64);

            $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')->exists();
            if ($exists) {
                continue;
            }

            $orphans = DB::table($table)->whereNotNull($column)
                ->whereNotIn($column, DB::table($parent)->select('id'))->count();
            if ($orphans > 0) {
                Log::warning("M-37: {$table}.{$column} has {$orphans} row(s) pointing at a missing {$parent} row — foreign key NOT added.");
                continue;
            }

            try {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`) ON DELETE ".strtoupper($onDelete));
            } catch (\Throwable $e) {
                Log::warning("M-37: could not add {$name}: ".$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        foreach ($this->keys as [$table, $column]) {
            $name = substr("fk_{$table}_{$column}", 0, 64);
            try { DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`"); } catch (\Throwable $e) {}
        }
    }
};
