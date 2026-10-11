<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  ForeignKeyRepair   (audit M-37)
//
//  Migration 000319 added the missing foreign keys, and skipped any
//  column that already held an id with no parent row. This retries
//  those columns: a "set null" column has the orphan id cleared,
//  then the key is added. A required column still holding an orphan
//  stops the migration and names the table, so nothing is skipped.
//
//  @layer    Support
// ══════════════════════════════════════════════════════════════════

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ForeignKeyRepair
{
    /** [table, column, parent table, on delete] */
    public function keys(): array
    {
        return [
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
    }

    public function apply(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->keys() as [$table, $column, $parent, $onDelete]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($parent)) {
                continue;
            }
            if ($this->constraintExists($table, $column)) {
                continue;
            }

            $this->resolveOrphans($table, $column, $parent, $onDelete);

            $name = substr("fk_{$table}_{$column}", 0, 64);
            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`) ON DELETE ".strtoupper($onDelete)
            );
        }
    }

    public function dropAll(): void
    {
        foreach ($this->keys() as [$table, $column]) {
            $name = substr("fk_{$table}_{$column}", 0, 64);
            try {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable $e) {
            }
        }
    }

    /** Clear "set null" orphans, or refuse when a required column still points nowhere. */
    public function resolveOrphans(string $table, string $column, string $parent, string $onDelete): void
    {
        $orphans = DB::table($table)->whereNotNull($column)
            ->whereNotIn($column, DB::table($parent)->select('id'))
            ->count();

        if ($orphans === 0) {
            return;
        }

        if ($onDelete === 'set null') {
            DB::table($table)->whereNotNull($column)
                ->whereNotIn($column, DB::table($parent)->select('id'))
                ->update([$column => null]);

            return;
        }

        throw new \RuntimeException(
            "{$table}.{$column} has {$orphans} orphan row(s) pointing at a missing {$parent} row. The foreign key was not added."
        );
    }

    public function constraintExists(string $table, string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
}
