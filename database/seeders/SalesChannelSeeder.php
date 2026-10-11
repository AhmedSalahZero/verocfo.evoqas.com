<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  SalesChannelSeeder
//
//  Seeds 5 system-level sales channels available to all companies.
//  company_id = NULL, is_system = TRUE — cannot be deleted.
//
//  Companies can add their own custom channels on top of these.
//
//  @layer    Seeder
//  @depends  SalesChannel
// ══════════════════════════════════════════════════════════════════

use App\Models\SalesChannel;
use Illuminate\Database\Seeder;

class SalesChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['name_en' => 'Direct',       'name_ar' => 'مباشر',          'sort_order' => 1],
            ['name_en' => 'Distributor',  'name_ar' => 'موزع',           'sort_order' => 2],
            ['name_en' => 'Export',       'name_ar' => 'تصدير',          'sort_order' => 3],
            ['name_en' => 'Online',       'name_ar' => 'أونلاين',        'sort_order' => 4],
            ['name_en' => 'Agent',        'name_ar' => 'وكيل',           'sort_order' => 5],
        ];

        foreach ($channels as $channel) {
            SalesChannel::updateOrCreate(
                ['company_id' => null, 'name_en' => $channel['name_en']],
                array_merge($channel, ['is_system' => true, 'is_active' => true])
            );
        }

        $this->command->info('SalesChannelSeeder: 5 system channels seeded.');
    }
}
