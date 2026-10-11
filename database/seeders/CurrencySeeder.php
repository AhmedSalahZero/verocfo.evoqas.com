<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  CurrencySeeder
//
//  Seeds standard currencies as system-level records.
//  company_id = NULL means available to all companies.
//
//  Includes:
//    — EGP (Egyptian Pound) — primary MENA functional currency
//    — Major international: USD, EUR, GBP
//    — GCC currencies: SAR, AED, QAR, KWD, BHD, OMR
//    — Other Arab: JOD, LBP, MAD, TND, LYD, SDG
//
//  Idempotent — uses updateOrCreate on code so re-running
//  updates without duplicating.
//
//  @layer    Seeder
//  @depends  Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            // ── Egyptian ──────────────────────────────────────────
            ['code' => 'EGP', 'name_en' => 'Egyptian Pound',       'name_ar' => 'الجنيه المصري',       'symbol' => 'ج.م',  'is_base' => true],

            // ── Major International ───────────────────────────────
            ['code' => 'USD', 'name_en' => 'US Dollar',            'name_ar' => 'الدولار الأمريكي',    'symbol' => '$',    'is_base' => false],
            ['code' => 'EUR', 'name_en' => 'Euro',                  'name_ar' => 'اليورو',              'symbol' => '€',    'is_base' => false],
            ['code' => 'GBP', 'name_en' => 'British Pound',        'name_ar' => 'الجنيه الإسترليني',   'symbol' => '£',    'is_base' => false],

            // ── GCC ───────────────────────────────────────────────
            ['code' => 'SAR', 'name_en' => 'Saudi Riyal',          'name_ar' => 'الريال السعودي',      'symbol' => 'ر.س',  'is_base' => false],
            ['code' => 'AED', 'name_en' => 'UAE Dirham',           'name_ar' => 'الدرهم الإماراتي',    'symbol' => 'د.إ',  'is_base' => false],
            ['code' => 'QAR', 'name_en' => 'Qatari Riyal',         'name_ar' => 'الريال القطري',       'symbol' => 'ر.ق',  'is_base' => false],
            ['code' => 'KWD', 'name_en' => 'Kuwaiti Dinar',        'name_ar' => 'الدينار الكويتي',     'symbol' => 'د.ك',  'is_base' => false],
            ['code' => 'BHD', 'name_en' => 'Bahraini Dinar',       'name_ar' => 'الدينار البحريني',    'symbol' => 'د.ب',  'is_base' => false],
            ['code' => 'OMR', 'name_en' => 'Omani Rial',           'name_ar' => 'الريال العُماني',     'symbol' => 'ر.ع',  'is_base' => false],

            // ── Other Arab ────────────────────────────────────────
            ['code' => 'JOD', 'name_en' => 'Jordanian Dinar',      'name_ar' => 'الدينار الأردني',     'symbol' => 'د.أ',  'is_base' => false],
            ['code' => 'LBP', 'name_en' => 'Lebanese Pound',       'name_ar' => 'الليرة اللبنانية',    'symbol' => 'ل.ل',  'is_base' => false],
            ['code' => 'MAD', 'name_en' => 'Moroccan Dirham',      'name_ar' => 'الدرهم المغربي',      'symbol' => 'د.م',  'is_base' => false],
            ['code' => 'TND', 'name_en' => 'Tunisian Dinar',       'name_ar' => 'الدينار التونسي',     'symbol' => 'د.ت',  'is_base' => false],
            ['code' => 'LYD', 'name_en' => 'Libyan Dinar',         'name_ar' => 'الدينار الليبي',      'symbol' => 'د.ل',  'is_base' => false],
            ['code' => 'SDG', 'name_en' => 'Sudanese Pound',       'name_ar' => 'الجنيه السوداني',     'symbol' => 'ج.س',  'is_base' => false],

            // ── Other International ───────────────────────────────
            ['code' => 'CHF', 'name_en' => 'Swiss Franc',          'name_ar' => 'الفرنك السويسري',     'symbol' => 'Fr',   'is_base' => false],
            ['code' => 'JPY', 'name_en' => 'Japanese Yen',         'name_ar' => 'الين الياباني',       'symbol' => '¥',    'is_base' => false],
            ['code' => 'CNY', 'name_en' => 'Chinese Yuan',         'name_ar' => 'اليوان الصيني',       'symbol' => '¥',    'is_base' => false],
            ['code' => 'TRY', 'name_en' => 'Turkish Lira',         'name_ar' => 'الليرة التركية',      'symbol' => '₺',    'is_base' => false],
            ['code' => 'INR', 'name_en' => 'Indian Rupee',         'name_ar' => 'الروبية الهندية',     'symbol' => '₹',    'is_base' => false],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code'], 'company_id' => null],
                array_merge($currency, ['company_id' => null, 'is_active' => true])
            );
        }

        $this->command->info('CurrencySeeder: ' . count($currencies) . ' currencies seeded.');
    }
}
