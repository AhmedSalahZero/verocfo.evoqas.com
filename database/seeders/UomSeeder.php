<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  UomSeeder
//
//  Seeds system UOMs (company_id = NULL).
//  Idempotent — uses updateOrCreate on (code, company_id).
//
//  @layer    Seeder
//  @depends  UnitOfMeasure
// ══════════════════════════════════════════════════════════════════

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class UomSeeder extends Seeder
{
    public function run(): void
    {
        $uoms = [
            // ── Count ──────────────────────────────────────────
            ['code' => 'UNIT',  'name_en' => 'Unit',          'name_ar' => 'وحدة',               'type' => 'count'],
            ['code' => 'COUNT', 'name_en' => 'Count / Piece',  'name_ar' => 'قطعة',               'type' => 'count'],
            ['code' => 'BOX',   'name_en' => 'Box',            'name_ar' => 'صندوق',              'type' => 'count'],
            ['code' => 'CTN',   'name_en' => 'Carton',         'name_ar' => 'كرتون',              'type' => 'count'],
            ['code' => 'BAG',   'name_en' => 'Bag',            'name_ar' => 'كيس',                'type' => 'count'],
            ['code' => 'ROLL',  'name_en' => 'Roll',           'name_ar' => 'رول',                'type' => 'count'],
            ['code' => 'SET',   'name_en' => 'Set',            'name_ar' => 'طقم',                'type' => 'count'],
            ['code' => 'PAIR',  'name_en' => 'Pair',           'name_ar' => 'زوج',                'type' => 'count'],
            ['code' => 'PKT',   'name_en' => 'Packet',         'name_ar' => 'حزمة',               'type' => 'count'],

            // ── Weight ─────────────────────────────────────────
            ['code' => 'KG',    'name_en' => 'Kilogram',       'name_ar' => 'كيلوجرام',           'type' => 'weight'],
            ['code' => 'G',     'name_en' => 'Gram',           'name_ar' => 'جرام',               'type' => 'weight'],
            ['code' => 'TON',   'name_en' => 'Ton',            'name_ar' => 'طن',                 'type' => 'weight'],
            ['code' => 'LB',    'name_en' => 'Pound',          'name_ar' => 'رطل',                'type' => 'weight'],

            // ── Volume ─────────────────────────────────────────
            ['code' => 'LTR',   'name_en' => 'Liter',          'name_ar' => 'لتر',                'type' => 'volume'],
            ['code' => 'ML',    'name_en' => 'Milliliter',      'name_ar' => 'مليلتر',             'type' => 'volume'],
            ['code' => 'M3',    'name_en' => 'Cubic Meter',     'name_ar' => 'متر مكعب',           'type' => 'volume'],
            ['code' => 'BRL',   'name_en' => 'Barrel',         'name_ar' => 'برميل',              'type' => 'volume'],
            ['code' => 'GAL',   'name_en' => 'Gallon',         'name_ar' => 'جالون',              'type' => 'volume'],

            // ── Length ─────────────────────────────────────────
            ['code' => 'M',     'name_en' => 'Meter',          'name_ar' => 'متر',                'type' => 'length'],
            ['code' => 'CM',    'name_en' => 'Centimeter',      'name_ar' => 'سنتيمتر',            'type' => 'length'],
            ['code' => 'MM',    'name_en' => 'Millimeter',      'name_ar' => 'مليمتر',             'type' => 'length'],
            ['code' => 'FT',    'name_en' => 'Foot',           'name_ar' => 'قدم',                'type' => 'length'],

            // ── Area ───────────────────────────────────────────
            ['code' => 'M2',    'name_en' => 'Square Meter',    'name_ar' => 'متر مربع',           'type' => 'area'],
            ['code' => 'FT2',   'name_en' => 'Square Foot',     'name_ar' => 'قدم مربع',           'type' => 'area'],

            // ── Time ───────────────────────────────────────────
            ['code' => 'HR',    'name_en' => 'Hour',           'name_ar' => 'ساعة',               'type' => 'time'],
            ['code' => 'DAY',   'name_en' => 'Day',            'name_ar' => 'يوم',                'type' => 'time'],
            ['code' => 'MON',   'name_en' => 'Month',          'name_ar' => 'شهر',                'type' => 'time'],
            ['code' => 'WK',    'name_en' => 'Week',           'name_ar' => 'أسبوع',              'type' => 'time'],
        ];

        foreach ($uoms as $uom) {
            UnitOfMeasure::updateOrCreate(
                ['code' => $uom['code'], 'company_id' => null],
                array_merge($uom, [
                    'company_id' => null,
                    'is_system'  => true,
                    'is_active'  => true,
                ])
            );
        }

        $this->command->info('UomSeeder: ' . count($uoms) . ' system UOMs seeded.');
    }
}
