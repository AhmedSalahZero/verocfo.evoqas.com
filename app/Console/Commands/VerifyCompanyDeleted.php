<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  VerifyCompanyDeleted
//
//  Checks the database for leftover rows after a company hard-delete
//  (CompanyService::destroy()) — the same information_schema
//  discovery approach that delete used, run in reverse: instead of
//  deleting company-scoped rows, it just counts whether any are
//  still sitting there.
//
//  TWO MODES:
//    php artisan demo:verify-company-deleted 13
//      Checks one specific company id: confirms the companies row
//      itself is gone, and that no table anywhere still has a row
//      with company_id = 13.
//
//    php artisan demo:verify-company-deleted
//      No id given — scans every company-scoped table for ANY row
//      whose company_id doesn't match a real, still-existing company
//      (catches leftovers from any past deletion, not just one).
//
//  USAGE (Windows / PowerShell / cmd — all the same):
//    php artisan demo:verify-company-deleted 13
//
//  Audit H8 (Sept 2026): audit_log is excluded from the leftover
//  check — company deletes now keep the audit trail on purpose.
//
//  @layer    Console Command
// ══════════════════════════════════════════════════════════════════

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyCompanyDeleted extends Command
{
    protected $signature = 'demo:verify-company-deleted {company_id? : The id of the company that was deleted. Omit to scan for any orphaned rows.}';

    protected $description = 'Confirms no leftover rows remain for a deleted company (or scans for any orphaned company-scoped data).';

    public function handle(): int
    {
        $companyId = $this->argument('company_id');

        $tables = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('column_name', 'company_id')
            ->select(DB::raw('table_name as tbl'))
            ->pluck('tbl')
            // audit_log rows are KEPT on purpose after a delete (Audit H8)
            ->reject(fn ($table) => in_array($table, ['companies', 'audit_log'], true))
            ->sort()
            ->values();

        if ($companyId) {
            return $this->checkSpecificCompany((int) $companyId, $tables);
        }

        return $this->scanForAnyOrphans($tables);
    }

    // ── Mode 1 — one specific company id ────────────────────────

    private function checkSpecificCompany(int $companyId, $tables): int
    {
        $this->info("Checking for any leftover data belonging to company id={$companyId} ...");
        $this->newLine();

        $companyRow = DB::table('companies')->where('id', $companyId)->first();
        if ($companyRow) {
            $this->error("The companies table STILL has a row with id={$companyId} ({$companyRow->name_en}) — it was not deleted.");
        } else {
            $this->line("✓ companies table — no row with id={$companyId}.");
        }

        $found = [];
        foreach ($tables as $table) {
            $count = DB::table($table)->where('company_id', $companyId)->count();
            if ($count > 0) {
                $found[$table] = $count;
            }
        }

        $this->newLine();

        if (empty($found) && ! $companyRow) {
            $this->info("Clean. Nothing in the database still references company id={$companyId}.");
            return self::SUCCESS;
        }

        if (! empty($found)) {
            $this->warn('Leftover rows found:');
            $rows = [];
            foreach ($found as $table => $count) {
                $rows[] = [$table, $count];
            }
            $this->table(['Table', 'Rows still referencing this company'], $rows);
        }

        return self::FAILURE;
    }

    // ── Mode 2 — scan everything for any orphan ─────────────────

    private function scanForAnyOrphans($tables): int
    {
        $this->info('Scanning every company-scoped table for rows pointing at a company that no longer exists ...');
        $this->newLine();

        $found = [];
        foreach ($tables as $table) {
            $count = DB::table($table)
                ->whereNotNull('company_id')
                ->whereNotIn('company_id', function ($query) {
                    $query->select('id')->from('companies');
                })
                ->count();
            if ($count > 0) {
                $found[$table] = $count;
            }
        }

        $this->newLine();

        if (empty($found)) {
            $this->info('Clean. No orphaned rows anywhere — every company_id in the database points at a real, existing company.');
            return self::SUCCESS;
        }

        $this->warn('Orphaned rows found (pointing at a company_id that no longer exists):');
        $rows = [];
        foreach ($found as $table => $count) {
            $rows[] = [$table, $count];
        }
        $this->table(['Table', 'Orphaned rows'], $rows);

        $this->newLine();
        $this->line('These are rows left behind by a company deletion that didn\'t fully clean up — safe to delete manually once you\'ve confirmed which company they belonged to.');

        return self::FAILURE;
    }
}
