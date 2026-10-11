<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  BackfillFunctionalCurrencies
//
//  One-off console command. CompanyService::create() was fixed in
//  v1.2 to auto-create the company_active_currencies row for a
//  company's functional currency — but that fix only applies to
//  companies created AFTER the fix went in. Any company created
//  before it has no such row, so its own base currency never shows
//  up in currency dropdowns that read via that pivot table.
//
//  This command finds every company missing that row and creates
//  it, matching the same logic CompanyService::create() now uses.
//
//  Safe to re-run — companies that already have an is_functional
//  row are skipped, not duplicated.
//
//  Usage:
//    php artisan currencies:backfill-functional
//
//  @layer    Console Command
//  @depends  Company, Currency, CompanyActiveCurrency, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFunctionalCurrencies extends Command
{
    protected $signature = 'currencies:backfill-functional';

    protected $description = 'Creates the missing company_active_currencies row for companies created before the CompanyService v1.2 fix.';

    public function handle(): int
    {
        $companies = Company::all();

        $fixed   = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($companies as $company) {

            $alreadyHasFunctional = CompanyActiveCurrency::where('company_id', $company->id)
                ->where('is_functional', true)
                ->exists();

            if ($alreadyHasFunctional) {
                $skipped++;
                continue;
            }

            $currencyId = Currency::withoutGlobalScopes()
                ->whereNull('company_id')
                ->where('code', $company->base_currency)
                ->value('id');

            if (! $currencyId) {
                $this->error("Company #{$company->id} ({$company->name_en}): no system currency found for code '{$company->base_currency}'. Skipped — please check this company's base_currency value.");
                $failed++;
                continue;
            }

            DB::transaction(function () use ($company, $currencyId) {
                $activation = CompanyActiveCurrency::create([
                    'company_id'    => $company->id,
                    'currency_id'   => $currencyId,
                    'is_functional' => true,
                    'is_active'     => true,
                ]);

                AuditLog::create([
                    'company_id'     => $company->id,
                    'user_id'        => null,
                    'action'         => 'backfill_functional_currency',
                    'auditable_type' => CompanyActiveCurrency::class,
                    'auditable_id'   => $activation->id,
                    'before'         => null,
                    'after'          => $activation->toArray(),
                    'created_at'     => now(),
                ]);
            });

            $this->info("Company #{$company->id} ({$company->name_en}): activated {$company->base_currency} as functional currency.");
            $fixed++;
        }

        $this->newLine();
        $this->info("Done. Fixed: {$fixed} | Already OK: {$skipped} | Failed: {$failed}");

        return self::SUCCESS;
    }
}
