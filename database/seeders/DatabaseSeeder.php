<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  Vero CFO — Master Database Seeder
//
//  Calls individual seeders in dependency order.
//  To run all:        php artisan db:seed
//  To run one:        php artisan db:seed --class=SuperAdminSeeder
//
//  FIX (July 2026): two bugs found and fixed here —
//  1. Called "BusinessSectorsSeeder" (with an 's') — the real class
//     is "BusinessSectorSeeder" (no 's'). Would have crashed the
//     entire db:seed run with a class-not-found error.
//  2. Five working seeders existed on disk but were never called
//     here: CurrencySeeder, ChartOfAccountSeeder, PaymentTermSeeder,
//     SalesChannelSeeder, UomSeeder. A fresh database would have had
//     a login user and business sectors but no currencies, no chart
//     of accounts, no payment terms — every dropdown in the app
//     would have been empty.
//
//  Order: all six are system-level seeders (company_id = NULL,
//  is_system = true) with no dependencies on each other, so order
//  between them doesn't matter — SuperAdminSeeder is listed first
//  simply because you need a login before anything else is useful.
//
//  @layer    Seeder
//  @depends  SuperAdminSeeder, BusinessSectorSeeder, CurrencySeeder,
//            ChartOfAccountSeeder, PaymentTermSeeder,
//            SalesChannelSeeder, UomSeeder
// ══════════════════════════════════════════════════════════════════

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            BusinessSectorSeeder::class,
            CurrencySeeder::class,
            ChartOfAccountSeeder::class,
            PaymentTermSeeder::class,
            SalesChannelSeeder::class,
            UomSeeder::class,
        ]);
    }
}
