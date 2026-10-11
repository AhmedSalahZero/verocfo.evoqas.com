<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  BusinessSectorSeeder
//
//  Seeds the platform-level business_sectors table.
//  No company_id — sectors are shared across all companies.
//
//  Coverage:
//    - 50+ sectors covering Egypt and MENA economy
//    - Bilingual (English + Arabic)
//    - Sorted by sort_order for consistent dropdown ordering
//    - Based on ETA activity codes, Egyptian commercial registry,
//      and MENA industry classification standards
//
//  Uses updateOrCreate keyed on name_en — safe to re-run.
//  Will update Arabic name and sort_order if already exists.
//
//  Run individually:
//    php artisan db:seed --class=BusinessSectorSeeder
//
//  @layer    Seeder
//  @depends  business_sectors table (migration 000012)
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSectorSeeder extends Seeder
{
    private array $sectors = [

        // ── Technology & Communications ───────────────────────────
        ['sort' =>  10, 'name_en' => 'Information Technology & Software',   'name_ar' => 'تكنولوجيا المعلومات والبرمجيات'],
        ['sort' =>  20, 'name_en' => 'Telecommunications',                  'name_ar' => 'الاتصالات'],
        ['sort' =>  30, 'name_en' => 'Electronics & Electrical Equipment',  'name_ar' => 'الإلكترونيات والمعدات الكهربائية'],
        ['sort' =>  40, 'name_en' => 'Media & Entertainment',               'name_ar' => 'الإعلام والترفيه'],
        ['sort' =>  50, 'name_en' => 'Digital Marketing & Advertising',     'name_ar' => 'التسويق الرقمي والإعلان'],

        // ── Financial Services ────────────────────────────────────
        ['sort' =>  60, 'name_en' => 'Banking',                             'name_ar' => 'البنوك'],
        ['sort' =>  70, 'name_en' => 'Insurance',                           'name_ar' => 'التأمين'],
        ['sort' =>  80, 'name_en' => 'Investment & Asset Management',       'name_ar' => 'الاستثمار وإدارة الأصول'],
        ['sort' =>  90, 'name_en' => 'Financial Services & Fintech',        'name_ar' => 'الخدمات المالية والتكنولوجيا المالية'],
        ['sort' => 100, 'name_en' => 'Microfinance & Leasing',              'name_ar' => 'التمويل متناهي الصغر والتأجير التمويلي'],

        // ── Industry & Manufacturing ──────────────────────────────
        ['sort' => 110, 'name_en' => 'Food & Beverage Manufacturing',       'name_ar' => 'تصنيع الأغذية والمشروبات'],
        ['sort' => 120, 'name_en' => 'Textiles & Garments',                 'name_ar' => 'المنسوجات والملابس'],
        ['sort' => 130, 'name_en' => 'Metal Fabrication & Steel',           'name_ar' => 'تشغيل المعادن والصلب'],
        ['sort' => 140, 'name_en' => 'Chemicals & Petrochemicals',          'name_ar' => 'الكيماويات والبتروكيماويات'],
        ['sort' => 150, 'name_en' => 'Plastics & Rubber',                   'name_ar' => 'البلاستيك والمطاط'],
        ['sort' => 160, 'name_en' => 'Automotive & Auto Parts',             'name_ar' => 'السيارات وقطع الغيار'],
        ['sort' => 170, 'name_en' => 'Pharmaceuticals & Medical Devices',   'name_ar' => 'الأدوية والأجهزة الطبية'],
        ['sort' => 180, 'name_en' => 'Paper & Printing',                    'name_ar' => 'الورق والطباعة'],
        ['sort' => 190, 'name_en' => 'Furniture & Wood Products',           'name_ar' => 'الأثاث ومنتجات الخشب'],
        ['sort' => 200, 'name_en' => 'Building Materials & Ceramics',       'name_ar' => 'مواد البناء والسيراميك'],

        // ── Construction & Real Estate ────────────────────────────
        ['sort' => 210, 'name_en' => 'Construction & Contracting',          'name_ar' => 'البناء والمقاولات'],
        ['sort' => 220, 'name_en' => 'Real Estate Development',             'name_ar' => 'التطوير العقاري'],
        ['sort' => 230, 'name_en' => 'Facilities Management',               'name_ar' => 'إدارة المرافق'],
        ['sort' => 240, 'name_en' => 'Interior Design & Fit-out',           'name_ar' => 'التصميم الداخلي والتشطيبات'],

        // ── Energy & Natural Resources ────────────────────────────
        ['sort' => 250, 'name_en' => 'Oil & Gas',                           'name_ar' => 'البترول والغاز'],
        ['sort' => 260, 'name_en' => 'Renewable Energy & Solar',            'name_ar' => 'الطاقة المتجددة والطاقة الشمسية'],
        ['sort' => 270, 'name_en' => 'Electricity & Utilities',             'name_ar' => 'الكهرباء والمرافق'],
        ['sort' => 280, 'name_en' => 'Mining & Quarrying',                  'name_ar' => 'التعدين والمحاجر'],

        // ── Trade & Distribution ──────────────────────────────────
        ['sort' => 290, 'name_en' => 'Wholesale Trading & Distribution',    'name_ar' => 'تجارة الجملة والتوزيع'],
        ['sort' => 300, 'name_en' => 'Retail & E-Commerce',                 'name_ar' => 'تجارة التجزئة والتجارة الإلكترونية'],
        ['sort' => 310, 'name_en' => 'Import & Export',                     'name_ar' => 'الاستيراد والتصدير'],
        ['sort' => 320, 'name_en' => 'Automotive Trading & Dealerships',    'name_ar' => 'تجارة السيارات والوكالات'],

        // ── Transport & Logistics ─────────────────────────────────
        ['sort' => 330, 'name_en' => 'Transportation & Logistics',          'name_ar' => 'النقل والخدمات اللوجستية'],
        ['sort' => 340, 'name_en' => 'Shipping & Freight Forwarding',       'name_ar' => 'الشحن والتخليص الجمركي'],
        ['sort' => 350, 'name_en' => 'Aviation & Airports',                 'name_ar' => 'الطيران والمطارات'],
        ['sort' => 360, 'name_en' => 'Maritime & Ports',                    'name_ar' => 'الملاحة البحرية والموانئ'],

        // ── Professional Services ─────────────────────────────────
        ['sort' => 370, 'name_en' => 'Accounting & Auditing',               'name_ar' => 'المحاسبة والمراجعة'],
        ['sort' => 380, 'name_en' => 'Legal Services',                      'name_ar' => 'الخدمات القانونية'],
        ['sort' => 390, 'name_en' => 'Management & Business Consulting',    'name_ar' => 'الاستشارات الإدارية والأعمال'],
        ['sort' => 400, 'name_en' => 'Engineering & Technical Consulting',  'name_ar' => 'الاستشارات الهندسية والتقنية'],
        ['sort' => 410, 'name_en' => 'Human Resources & Recruitment',       'name_ar' => 'الموارد البشرية والتوظيف'],
        ['sort' => 420, 'name_en' => 'System Integration & IT Services',    'name_ar' => 'تكامل الأنظمة وخدمات تكنولوجيا المعلومات'],

        // ── Healthcare ────────────────────────────────────────────
        ['sort' => 430, 'name_en' => 'Hospitals & Healthcare Services',     'name_ar' => 'المستشفيات والرعاية الصحية'],
        ['sort' => 440, 'name_en' => 'Pharmaceuticals Distribution',        'name_ar' => 'توزيع الأدوية'],
        ['sort' => 450, 'name_en' => 'Medical Laboratories & Diagnostics',  'name_ar' => 'المختبرات الطبية والتشخيص'],

        // ── Education & Training ──────────────────────────────────
        ['sort' => 460, 'name_en' => 'Education & Universities',            'name_ar' => 'التعليم والجامعات'],
        ['sort' => 470, 'name_en' => 'Training & Professional Development', 'name_ar' => 'التدريب والتطوير المهني'],

        // ── Tourism & Hospitality ─────────────────────────────────
        ['sort' => 480, 'name_en' => 'Hotels & Hospitality',                'name_ar' => 'الفنادق والضيافة'],
        ['sort' => 490, 'name_en' => 'Tourism & Travel Agencies',           'name_ar' => 'السياحة ووكالات السفر'],
        ['sort' => 500, 'name_en' => 'Restaurants & Food Service',          'name_ar' => 'المطاعم وخدمات الطعام'],

        // ── Agriculture ───────────────────────────────────────────
        ['sort' => 510, 'name_en' => 'Agriculture & Farming',               'name_ar' => 'الزراعة والمزارع'],
        ['sort' => 520, 'name_en' => 'Food Processing & Agro-Industry',     'name_ar' => 'تصنيع الأغذية والصناعات الزراعية'],
        ['sort' => 530, 'name_en' => 'Fisheries & Aquaculture',             'name_ar' => 'مصايد الأسماك والاستزراع السمكي'],

        // ── Public & Social Sector ────────────────────────────────
        ['sort' => 540, 'name_en' => 'Government & Public Sector',          'name_ar' => 'الحكومة والقطاع العام'],
        ['sort' => 550, 'name_en' => 'Non-Profit & NGO',                    'name_ar' => 'المنظمات غير الربحية والمجتمع المدني'],
        ['sort' => 560, 'name_en' => 'Social Enterprises',                  'name_ar' => 'المشاريع الاجتماعية'],

        // ── Other ─────────────────────────────────────────────────
        ['sort' => 990, 'name_en' => 'Other',                               'name_ar' => 'أخرى'],
    ];

    public function run(): void
    {
        $this->command->info('Seeding business sectors...');

        $inserted = 0;
        $updated  = 0;

        foreach ($this->sectors as $sector) {
            $exists = DB::table('business_sectors')
                ->where('name_en', $sector['name_en'])
                ->first();

            if ($exists) {
                DB::table('business_sectors')
                    ->where('id', $exists->id)
                    ->update([
                        'name_ar'    => $sector['name_ar'],
                        'sort_order' => $sector['sort'],
                        'updated_at' => now(),
                    ]);
                $updated++;
            } else {
                DB::table('business_sectors')->insert([
                    'name_en'    => $sector['name_en'],
                    'name_ar'    => $sector['name_ar'],
                    'sort_order' => $sector['sort'],
                    'is_active'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        $this->command->info("✓ Done — {$inserted} sectors inserted, {$updated} updated.");
    }
}
